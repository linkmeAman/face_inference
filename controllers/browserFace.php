<?php
require_once dirname(__DIR__) . '/classes/dbconnection.class.php';
require_once dirname(__DIR__) . '/classes/session.class.php';
require_once dirname(__DIR__) . '/classes/services/BrowserFaceService.php';
require_once dirname(__DIR__) . '/classes/services/BrowserFacePilot.php';

final class browserFace extends dbconnection
{
    private $serviceFactory;
    public function __construct(?callable $serviceFactory=null) {$this->serviceFactory=$serviceFactory;}
    public static function validateBrowserRequest(array $server, array $operator, string $method): void
    {
        if (empty($operator['db_name']) || (int)($operator['user_id'] ?? $operator['uid'] ?? 0) < 1 || (int)($operator['bid'] ?? 0) < 1)
            throw new InvalidArgumentException('AUTH_REQUIRED');
        foreach (['HTTP_UID','HTTP_BID','HTTP_DEVICE','HTTP_TOKEN'] as $field)
            if (!empty($server[$field])) throw new InvalidArgumentException('Cookie authentication required');
        $local = PHP_SAPI === 'cli-server' && in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
            && in_array($server['HTTP_HOST'] ?? '', ['127.0.0.1:8137','localhost:8137'], true);
        if (($server['HTTPS'] ?? '') !== 'on' && !$local) throw new InvalidArgumentException('HTTPS_REQUIRED');
        if ($method !== 'GET') {
            $token = $server['HTTP_X_FACE_CSRF'] ?? '';
            if (!is_string($token) || empty($operator['face_csrf']) || !hash_equals($operator['face_csrf'], $token))
                throw new InvalidArgumentException('CSRF_REQUIRED');
            $expected = ($local ? 'http://' : 'https://') . ($server['HTTP_HOST'] ?? '');
            if (!empty($server['HTTP_ORIGIN']) && $server['HTTP_ORIGIN'] !== $expected) throw new InvalidArgumentException('ORIGIN_REJECTED');
        }
    }

    public function handle(string $action, array $payload, string $method): array
    {
        self::validateBrowserRequest($_SERVER, $_SESSION, $method);
        if (!in_array($action, ['config','health','options','roster','migration_status','calibration_progress','enrollment_status','pilot_roster','pilot_attendance','pilot_batches','test_attendance'], true) && $method !== 'POST') throw new InvalidArgumentException('POST required');
        $actor = (int)($_SESSION['user_id'] ?? $_SESSION['uid']); $branch = (int)$_SESSION['bid'];
        $session = new session;
        if (!$session->access($actor, $this->module('Attendance'), 1)) throw new InvalidArgumentException('ATTENDANCE_ACCESS');
        $policy = BrowserFacePolicy::load(); $contract = BrowserFacePolicy::contract();
        $key=FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY');$keyId=FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY_ID');
        if ($key==='' || $keyId==='') throw new RuntimeException('Face template encryption is not configured');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $pilot=null;$database=$_SESSION['db_name'];
        if(($_GET['pilot'] ?? '')==='1'){$pilot=BrowserFacePilot::load($database,$branch);BrowserFacePilot::request($pilot,$action,$payload);$database=$pilot['database'];}
        $db=$this->connect($database);$db->set_charset('utf8mb4');
        try {
            if($pilot){BrowserFacePilot::verify($db,$pilot);$policy=BrowserFacePilot::policy($pilot);}
            else {
                $batch=(int)($payload['batch_id'] ?? $payload['evaluation_context']['batch_id'] ?? 0);
                if(!$batch && in_array($action,['attendance','confirm','finalize','close','camera_event'],true) && is_int($payload['scan_id'] ?? null)){
                    $s=$db->prepare('SELECT batch_id FROM attendance_scan WHERE id=? AND branch_id=? AND actor_employee_id=?');$s->bind_param('iii',$payload['scan_id'],$branch,$actor);$s->execute();$batch=(int)($s->get_result()->fetch_row()[0] ?? 0);$s->close();
                }
                $policy+=BrowserFacePolicy::rollout($database,$branch,$batch,$policy,null,$actor);
            }
            if(in_array($action,['pilot_roster','pilot_attendance','pilot_batches'],true)){
                if(!$pilot)throw new InvalidArgumentException('Pilot-only operation');
                $date=$payload['attendance_date'] ?? '';if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$date))throw new InvalidArgumentException('Attendance date required');$scheduled=(int)($payload['session_id'] ?? 0);
                if($action==='pilot_batches'){
                    $service=new BrowserFaceService($db,new FaceEmbeddingCipher($key,$keyId),$actor,$branch,$policy);$roster=$service->roster(['batch_id'=>33,'attendance_date'=>$date]);
                    $batch=$db->query('SELECT id,batch,offline FROM batch WHERE id=33')->fetch_assoc();$batch['employee']=[];$batch['batch_time']=array_map(fn($s)=>['session_id'=>(string)$s['id'],'start_time'=>$s['start_time'],'end_time'=>$s['end_time'],'module'=>'','title'=>'','module_id'=>'0'],$roster['sessions']);return ['records'=>[$batch]];
                }
                if($action==='pilot_attendance'){$s=$db->prepare('SELECT * FROM attendance WHERE batch_id=33 AND date=? AND session_id=? AND park=0');$s->bind_param('si',$date,$scheduled);}
                else{$s=$db->prepare("SELECT m.contact_id,m.code,CONCAT_WS(' ',c.fname,c.lname) AS fullname,i.sessions,i.sessions_used,i.start_date,i.end_date,i.item_id,COALESCE(a.id,0) AS attendance_id,COALESCE(a.negative,1) AS negative FROM member m JOIN contact c ON c.id=m.contact_id JOIN invoice_invoiceitem_module_view i ON i.contact_id=m.contact_id AND i.batch_id=33 LEFT JOIN attendance a ON a.contact_id=m.contact_id AND a.batch_id=33 AND a.date=? AND a.session_id=? AND a.park=0 WHERE m.bid=2 AND i.park=0 AND i.item_park=0 AND i.start_date<=? AND i.end_date>=?");$s->bind_param('siss',$date,$scheduled,$date,$date);}
                $s->execute();return ['records'=>$s->get_result()->fetch_all(MYSQLI_ASSOC)];
            }
            $cipher=new FaceEmbeddingCipher($key,$keyId);
            $service=$this->serviceFactory ? ($this->serviceFactory)($db,$cipher,$actor,$branch,$policy) : new BrowserFaceService($db,$cipher,$actor,$branch,$policy);
            if(in_array($action,['options','enrollment_status'],true) && $policy['ready'] && ($pilot || ($policy['operating_mode'] ?? '')==='LIVE') && $service->lifecycleAvailable()){
                $s=$db->prepare("SELECT COUNT(*) FROM face_web_enrollment WHERE branch_id=? AND state='PENDING'");$s->bind_param('i',$branch);$s->execute();
                if((int)$s->get_result()->fetch_row()[0])$service->run('activate_pending',['idempotency_key'=>'automatic_activation_'.bin2hex(random_bytes(16))]);
            }
            if ($action === 'config') {
                $mode=$pilot && $policy['ready']?'LIVE':($policy['operating_mode'] ?? 'CAPTURE_ONLY');
                // Resume settings reuse the existing evidence-bound policy;
                // absent settings fail closed after a capture interruption.
                $same=!empty($policy['testing_evaluation'])?($policy['TEST_DUPLICATE_THRESHOLD'] ?? null):($policy['ready']?($policy['DUPLICATE_THRESHOLD'] ?? null):null);
                $different=!empty($policy['testing_evaluation'])?($policy['TEST_ATTENDANCE_THRESHOLD'] ?? null):($policy['ready']?($policy['ATTENDANCE_THRESHOLD'] ?? null):null);
                $continuity=is_numeric($same) && is_numeric($different)?['same_min'=>max((float)$same,(float)$different),'different_max'=>min((float)$same,(float)$different),'observations'=>$contract['minimum_observations'],'provisional'=>!empty($policy['testing_evaluation']) || !empty($pilot)]:null;
                return $contract + ['capture_continuity'=>$continuity,'release_version' =>'frictionless-20261016-1','reference_preview_available'=>false,'supplemental_templates_enabled'=>false,'operating_mode'=>$mode,'scanning_available'=>in_array($mode,['SHADOW','TEST','LIVE'],true),'test_evaluation_ready'=>!empty($policy['testing_evaluation']),'acceptance_ready'=>$policy['ready'],'enrollment_collection_available'=>($policy['capture_available'] ?? true),'lifecycle_available'=>$service->lifecycleAvailable(),'isolated_pilot'=>(bool)$pilot,'pilot_mode'=>$pilot?($policy['mode'] ?? ''):'','pilot_notice'=>$pilot?($policy['notice'] ?? ''):'','reason'=>$policy['reason'],'availability_message'=>$mode==='DISABLED'?'Face capture and scanning are not enabled for this class. Please use manual attendance.':($mode==='CAPTURE_ONLY'?'Face capture is available. Scanning is not enabled for this class yet.':($mode==='TEST'?'Testing only. Scan members, then Punch Attendance to save test results.':($mode==='SHADOW'?'Review face suggestions and confirm attendance after checking each child.':''))),'attendance_threshold'=>$policy['ATTENDANCE_THRESHOLD']];
            }
            if($action==='test_attendance')return $service->testAttendanceStatus(['batch_id'=>(int)($payload['batch_id'] ?? 0),'attendance_date'=>$payload['attendance_date'] ?? '', 'attendance_session_id'=>(int)($payload['attendance_session_id'] ?? -1),'selected_time'=>$payload['selected_time'] ?? '']);
            if($action==='enrollment_status')return $service->enrollmentStatus();
            if ($action==='health') {
                $assets=dirname(__DIR__).'/pf/vendor/browser-face/';
                $manifest=is_readable($assets.'manifest.json') ? json_decode(file_get_contents($assets.'manifest.json'),true) : [];
                $ready=true;
                foreach(['sface.onnx','yunet.onnx'] as $file) $ready=$ready && !empty($manifest[$file]) && is_readable($assets.$file) && hash_equals($manifest[$file],hash_file('sha256',$assets.$file));
                $db->query('SELECT actor_id FROM face_web_request LIMIT 0');
                $db->query('SELECT id FROM face_web_calibration LIMIT 0');
                $db->query('SELECT id FROM face_web_migration LIMIT 0');
                return ['assets_ready'=>$ready,'acceptance_ready'=>$ready && $policy['ready'],'reason'=>$policy['reason'],'raw_images_retained'=>false];
            }
            if ($action==='migration_status') return $service->migrationStatus();
            if ($action==='calibration_progress') return $service->calibrationProgress();
            if ($action==='options') return $service->options();
            if ($action==='roster') return $service->roster(['batch_id'=>(int)($payload['batch_id'] ?? 0),'attendance_date'=>$payload['attendance_date'] ?? null]);
            return $service->run($action,$payload);
        } finally { $db->close(); }
    }
}
