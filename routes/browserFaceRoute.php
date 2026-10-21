<?php
// This endpoint intentionally uses same-origin cookies, not the legacy CORS bootstrap.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors','0');
session_start();
require_once dirname(__DIR__) . '/controllers/browserFace.php';
$action=$_GET['action'] ?? '';$payload=[];$faceRequestId=bin2hex(random_bytes(16));
header('X-Face-Request-ID: '.$faceRequestId);
function faceRouteFailure(string $reason):void {
    $key=$GLOBALS['payload']['idempotency_key'] ?? $GLOBALS['faceRequestId'];
    $key=is_string($key) && preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key)?$key:bin2hex(random_bytes(16));
    $operation=is_string($GLOBALS['action']) && in_array($GLOBALS['action'],['config','health','options','roster','enroll','attendance','open','close','confirm','finalize','camera_event','enrollment_status','validate_capture','calibration_collect','calibration_delete','delete','activate_pending','migration_status','migrate','pilot_roster','pilot_attendance','pilot_batches','test_punch','test_attendance'],true)?$GLOBALS['action']:'invalid';
    BrowserFaceAudit::write('backend_error',['request_id'=>$key,'operation'=>$operation,'tenant'=>$_SESSION['db_name'] ?? '', 'branch_id'=>(int)($_SESSION['bid'] ?? 0),'actor_id'=>(int)($_SESSION['user_id'] ?? 0),'reason'=>$reason,'response_status'=>http_response_code()]+($reason==='SERVICE_UNAVAILABLE'?['persistence_outcome'=>'UNKNOWN_RECONCILE_IDEMPOTENCY']:['persistence_succeeded'=>false]));
}
try {
    $method=$_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method,['GET','POST'],true)) {http_response_code(405);throw new InvalidArgumentException('GET or POST required');}
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>65536) {http_response_code(413);throw new InvalidArgumentException('Request too large');}
    $payload=$_GET;
    if ($method==='POST') {
        if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')!==0) throw new InvalidArgumentException('JSON required');
        $raw=file_get_contents('php://input',false,null,0,65537);
        if(strlen($raw)>65536) {http_response_code(413);throw new InvalidArgumentException('Request too large');}
        $payload=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new InvalidArgumentException('JSON object required');
    }
    $action=$_GET['action'] ?? '';
    if(!is_string($action))throw new InvalidArgumentException('Invalid action');
    if(is_string($payload['idempotency_key'] ?? null) && preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$payload['idempotency_key']))header('X-Face-Request-ID: '.$payload['idempotency_key']);
    $result=(new browserFace())->handle($action,$payload,$method);
    echo json_encode(['error'=>0,'error_msg'=>'','message'=>$result['message'] ?? '', 'data'=>$result],JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    if (http_response_code()===200) http_response_code(400);
    if ($error->getMessage()==='AUTH_REQUIRED') http_response_code(401);
    if (in_array($error->getMessage(),['CSRF_REQUIRED','ORIGIN_REJECTED','ATTENDANCE_ACCESS'],true)) http_response_code(403);
    faceRouteFailure('REQUEST_REJECTED');
    echo json_encode(['error'=>1,'error_msg'=>$error->getMessage(),'message'=>$error->getMessage(),'data'=>[]]);
} catch (JsonException $error) {
    http_response_code(400);
    faceRouteFailure('INVALID_JSON');
    echo json_encode(['error'=>1,'error_msg'=>'Invalid JSON request','message'=>'Invalid JSON request','data'=>[]]);
} catch (Throwable $error) {
    http_response_code(503);
    faceRouteFailure('SERVICE_UNAVAILABLE');
    // No exceptions, embeddings, raw images, keys or SQL in ordinary responses/logs.
    echo json_encode(['error'=>1,'error_msg'=>'Face camera is unavailable. Please contact the administrator.','message'=>'Face camera is unavailable. Please contact the administrator.','data'=>[]]);
}
