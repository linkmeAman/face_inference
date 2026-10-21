<?php
// This router is loaded only by the dedicated CLI test server. No production
// development mode or calibration bypass is added to the application routes.
if(PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)) {http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(!in_array($path,['/fixture-login','/fixture-test-api','/fixture-test-counts','/fixture-api','/fixture-pending-api','/fixture-shadow-api','/fixture-shadow-counts','/fixture-counts','/fixture-pilot-login','/fixture-pilot-api','/fixture-pilot-counts','/fixture-pilot-demo-enable'],true))return false;
$name=getenv('FACE_BROWSER_TEST_DB');$secret=getenv('FACE_BROWSER_TEST_KEY');
if(!$secret || !preg_match('/^ccc_database_test_browser_[a-f0-9]{8}$/D',(string)$name)){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/controllers/browserFace.php';
session_start();header('Content-Type: application/json');
if(in_array($path,['/fixture-login','/fixture-pilot-login'],true)) {
    if(!hash_equals($secret,$_SERVER['HTTP_X_TEST_KEY'] ?? '')){http_response_code(403);exit;}
    // Use an actually authorized existing operator for real central permission
    // checks, without changing that operator or central database.
    $db=(new dbconnection())->connect('ccc_central');
    $row=$db->query("SELECT up.user_id FROM user_permission up JOIN client_module cm ON cm.id=up.cm_id JOIN module m ON m.id=cm.module_id WHERE m.module='Attendance' AND up.bid=2 AND up.permission>=1 AND cm.active=1 LIMIT 1")->fetch_assoc();
    if(!$row)throw new RuntimeException('Read-only authorized operator fixture unavailable');
    session_regenerate_id(true);$_SESSION=['db_name'=>$name,'user_id'=>(int)$row['user_id'],'bid'=>2,'face_csrf'=>bin2hex(random_bytes(32))];
    if($path==='/fixture-pilot-login' || isset($_GET['shell']))$_SESSION+=['config'=>[],'position_id'=>(int)($_GET['position'] ?? 10),'admin'=>0,'contact_id'=>0,'user_type'=>'E','user_name'=>'Isolated browser fixture','branch'=>[['id'=>2,'type'=>0]],'token'=>'isolated-fixture-only','permissions'=>[],'inst_logo'=>'logo_ccc1.png','home_page'=>'/pf/attendance.php','session_id'=>session_id()];
    if($path==='/fixture-pilot-login')$_SESSION['db_name']='ccc_database';
    echo json_encode(['csrf'=>$_SESSION['face_csrf']]);exit;
}
if(($_SESSION['db_name'] ?? '')!==$name && !(strpos($path,'/fixture-pilot-')===0 && ($_SESSION['db_name'] ?? '')==='ccc_database')){http_response_code(401);echo '{"error":1,"error_msg":"AUTH_REQUIRED"}';exit;}
if($path==='/fixture-pilot-demo-enable'){
    browserFace::validateBrowserRequest($_SERVER,$_SESSION,$_SERVER['REQUEST_METHOD']);if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('POST only');
    $file='/tmp/ccc-browser-face-pilot-fixture.json';$config=BrowserFacePilot::load('ccc_database',2,$file);
    if($config['database']!=='ccc_database_face_pilot_b1002026' || !hash_equals($config['owner_token'],hash('sha256',$secret.':owned-disposable-pilot')))throw new RuntimeException('Disposable fixture only');
    $db=(new dbconnection)->connect($config['database']);BrowserFacePilot::verify($db,$config);
    $reference=$db->query("SELECT ft.id AS template_id,e.id AS enrollment_id,e.calibration_id,e.capture_session,SHA2(ft.encrypted_embedding,256) AS template_sha256,SHA2(e.encrypted_template,256) AS enrollment_sha256 FROM face_template ft JOIN face_web_enrollment e ON e.active_template_id=ft.id WHERE ft.member_id=623 AND ft.status='ACTIVE'")->fetch_assoc();
    if(!$reference)throw new RuntimeException('Real-model fixture enrollment required');
    foreach(['template_id','enrollment_id','calibration_id'] as $key)$reference[$key]=(int)$reference[$key];$reference['member_id']=623;$contract=BrowserFacePolicy::contract();
    // Structural authorization fixture only; no claim of real consented
    // people, calibration accuracy or production thresholds from this photo.
    $report=['test_fixture_only'=>true,'source'=>'consented-live-cameras','model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version'],'dataset_databases'=>[$config['database']],'dataset_branches'=>[2],'identity_ids'=>[623],'provisional_pilot'=>['ready'=>true,'mode'=>'SINGLE_IDENTITY_LIVE_DEMO','functional_demo_only'=>true,'production_approved'=>false,'approved_by'=>'owned disposable regression fixture','expires_at'=>$config['expires_at'],'identities'=>1,'genuine_trials'=>1,'DUPLICATE_THRESHOLD'=>.99,'ATTENDANCE_THRESHOLD'=>.99,'reference'=>$reference]];
    $reportFile='/tmp/ccc-browser-face-pilot-demo-fixture.json';$old=umask(0077);try{file_put_contents($reportFile,json_encode($report,JSON_THROW_ON_ERROR));chmod($reportFile,0600);}finally{umask($old);}
    $config['report_path']=$reportFile;$config['report_sha256']=hash_file('sha256',$reportFile);BrowserFacePilot::verify($db,$config);
    file_put_contents($file,json_encode($config,JSON_THROW_ON_ERROR));echo json_encode(['enabled'=>BrowserFacePilot::policy($config)['ready']]);exit;
}
if($path==='/fixture-pilot-counts'){
    $config=BrowserFacePilot::load('ccc_database',2);$db=(new dbconnection)->connect($config['database']);BrowserFacePilot::verify($db,$config);
    echo json_encode(['templates'=>(int)$db->query("SELECT COUNT(*) FROM face_template WHERE status='ACTIVE'")->fetch_row()[0],'attendance'=>(int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0],'events'=>(int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0],'pending'=>(int)$db->query("SELECT COUNT(*) FROM face_web_enrollment WHERE state='PENDING'")->fetch_row()[0]]);exit;
}
if($path==='/fixture-test-counts'){
    $config=BrowserFacePilot::load('ccc_database',2);$db=(new dbconnection)->connect($config['database']);BrowserFacePilot::verify($db,$config);
    echo json_encode(['attendance'=>(int)$db->query('SELECT COUNT(*) FROM face_testing_attendance')->fetch_row()[0],'events'=>(int)$db->query('SELECT COUNT(*) FROM face_testing_attendance_event')->fetch_row()[0]]);exit;
}
if($path==='/fixture-shadow-counts'){
    $db=(new dbconnection)->connect($name);
    $last=$db->query("SELECT response FROM face_web_request WHERE JSON_UNQUOTE(JSON_EXTRACT(response,'$.state'))='SHADOW_EVALUATED' ORDER BY created_at DESC LIMIT 1")->fetch_row();
    echo json_encode(['pending'=>(int)$db->query("SELECT COUNT(*) FROM face_web_enrollment WHERE state='PENDING'")->fetch_row()[0],'shadow_requests'=>(int)$db->query("SELECT COUNT(*) FROM face_web_request WHERE JSON_UNQUOTE(JSON_EXTRACT(response,'$.state'))='SHADOW_EVALUATED'")->fetch_row()[0],'last'=>$last?json_decode($last[0],true):null]);exit;
}
if($path==='/fixture-counts') {
    $db=(new dbconnection())->connect($name);
    echo json_encode(['templates'=>(int)$db->query("SELECT COUNT(*) FROM face_template WHERE status='ACTIVE'")->fetch_row()[0],
        'attendance'=>(int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0],
        'events'=>(int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0],
        'deductions'=>(int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=21')->fetch_row()[0]]);exit;
}
try {
    $action=$_GET['action'] ?? '';$method=$_SERVER['REQUEST_METHOD'];$payload=$method==='POST' ? json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR) : $_GET;
    if($path==='/fixture-pilot-api')$_GET['pilot']='1';
    $fixturePolicy=$path==='/fixture-pending-api' ? ['ready'=>false,'DUPLICATE_THRESHOLD'=>null,'ATTENDANCE_THRESHOLD'=>null] : ['ready'=>true,'DUPLICATE_THRESHOLD'=>.99,'ATTENDANCE_THRESHOLD'=>.99];
    if($path==='/fixture-test-api')$fixturePolicy=['ready'=>false,'ATTENDANCE_THRESHOLD'=>null,'DUPLICATE_THRESHOLD'=>null,'operating_mode'=>'TEST','testing_evaluation'=>true,'TEST_ATTENDANCE_THRESHOLD'=>.99,'TEST_DUPLICATE_THRESHOLD'=>.99,'test_report_sha256'=>str_repeat('d',64),'capture_available'=>true];
    $actualDemo=$path==='/fixture-pilot-api' && (BrowserFacePilot::policy(BrowserFacePilot::load('ccc_database',2))['mode'] ?? '')==='SINGLE_IDENTITY_LIVE_DEMO';
    $actualShadow=$path==='/fixture-shadow-api';
    $controller=($actualDemo || $actualShadow)?new browserFace():new browserFace(fn($db,$cipher,$actor,$branch,$policy)=>new BrowserFaceService($db,$cipher,$actor,$branch,$fixturePolicy));
    // Readiness reflects this isolated service's constructor-injected policy.
    // The normal production route/configuration is never altered.
    $result=$controller->handle($action,$payload,$method);
    if($action==='config' && !$actualDemo && !$actualShadow){$result['operating_mode']=$fixturePolicy['ready']?'LIVE':'CAPTURE_ONLY';$result['scanning_available']=$fixturePolicy['ready'];$result['attendance_threshold']=$fixturePolicy['ATTENDANCE_THRESHOLD'];$result['acceptance_ready']=$fixturePolicy['ready'];$result['availability_message']=$fixturePolicy['ready']?'':'Face capture is available. Scanning is not enabled for this class yet.';}
    if($action==='config' && $path==='/fixture-test-api')$result=array_replace($result,['operating_mode'=>'TEST','scanning_available'=>true,'test_evaluation_ready'=>true,'acceptance_ready'=>false,'enrollment_collection_available'=>true,'attendance_threshold'=>null,'availability_message'=>'Testing only. Recognition does not save attendance.']);
    if($action==='config' && !$actualDemo && !$actualShadow)$result['capture_continuity']=['same_min'=>.99,'different_max'=>.99,'observations'=>3,'provisional'=>true];
    echo json_encode(['error'=>0,'error_msg'=>'','message'=>$result['message'] ?? '','data'=>$result]);
}catch(Throwable $error){http_response_code(400);echo json_encode(['error'=>1,'error_msg'=>$error->getMessage(),'data'=>[]]);}
