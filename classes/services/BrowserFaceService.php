<?php
require_once __DIR__ . '/FaceEnrollmentService.php';
require_once __DIR__ . '/AttendanceDomainService.php';
require_once __DIR__ . '/BrowserFacePolicy.php';
require_once __DIR__ . '/BrowserFaceMath.php';
require_once __DIR__.'/BrowserFaceAudit.php';
require_once __DIR__.'/BrowserFaceTestAttendance.php';

/** Browser vectors are untrusted. This service owns central decisions only. */
final class BrowserFaceService
{
    private $db;
    private $cipher;
    private $contract;
    private $policy;
    private $actor;
    private $branch;
    private $modelId;
    private $lifecycle=false;
    private $auditContext=[];
    private $auditStarted=0;
    private $testStoreFactory;

    public function __construct(mysqli $db, FaceEmbeddingCipher $cipher, int $actor, int $branch, array $policy, ?callable $testStoreFactory=null)
    {
        if ($actor < 1 || $branch < 1) throw new InvalidArgumentException('Authenticated operator and branch required');
        $this->db = $db; $this->cipher = $cipher; $this->actor = $actor; $this->branch = $branch;
        $this->contract = BrowserFacePolicy::contract(); $this->policy = $policy; $this->testStoreFactory=$testStoreFactory;
        $row = $this->one('SELECT id FROM face_model_version WHERE model_name=? AND preprocessing_version=? AND model_checksum=? AND embedding_dimension=128 AND status IN (\'ACTIVE\',\'PENDING_CALIBRATION\')',
            [$this->contract['model_version'], $this->contract['preprocessing_version'], $this->contract['recognizer_source_sha256']]);
        if (!$row) throw new RuntimeException('Browser face migration is required');
        $this->modelId = (int)$row['id'];
        try{$db->query('SELECT id FROM face_web_enrollment LIMIT 0');$db->query('SELECT id FROM face_web_evidence LIMIT 0');$this->lifecycle=true;}catch(mysqli_sql_exception $e){if((int)$e->getCode()!==1146)throw $e;}
    }
    public function lifecycleAvailable(): bool {return $this->lifecycle;}
    private function mode(): string {return $this->policy['operating_mode'] ?? ($this->policy['ready']?'LIVE':'CAPTURE_ONLY');}
    private function activationAllowed(): bool {return $this->policy['ready'] && $this->mode()==='LIVE' && (!isset($this->policy['operating_mode']) || !empty($this->policy['isolated_pilot']) || !empty($this->policy['rollout_authorized']));}
    private function scanBinding(): string {
        if($this->mode()==='TEST')return 'browser-face-TEST:'.hash('sha256',($this->policy['test_report_sha256'] ?? '').':testing-punch-v3');
        return $this->mode()==='LIVE'?'browser-face-LIVE:'.hash('sha256',json_encode([$this->policy['report_sha256'] ?? null,$this->policy['rollout_scope_sha256'] ?? null,$this->policy['ATTENDANCE_THRESHOLD'] ?? null])):(isset($this->policy['operating_mode'])?'browser-face-'.$this->mode():'browser-face-calibration-v1');
    }

    public static function vector($values, int $dimension = 128): array
    {
        if (!is_array($values) || array_keys($values) !== range(0, $dimension - 1)) throw new InvalidArgumentException('Invalid embedding dimension');
        $sum = 0;
        foreach ($values as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) throw new InvalidArgumentException('Embedding values must be finite numbers');
            $sum += $value * $value;
        }
        if (abs(sqrt($sum) - 1) > 0.001) throw new InvalidArgumentException('Embedding must be L2 normalized');
        return array_map('floatval', $values);
    }

    public static function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b)) throw new InvalidArgumentException('Embedding dimension mismatch');
        $score = 0;
        foreach ($a as $i => $value) $score += $value * $b[$i];
        return max(-1.0, min(1.0, $score));
    }

    private function audit(string $event,array $extra=[]): void
    {
        BrowserFaceAudit::write($event,array_replace($this->auditContext,['duration_ms'=>round((microtime(true)-$this->auditStarted)*1000,2)],$extra));
    }
    private function auditResult(string $action,array $response,bool $replay=false): void
    {
        $meta=['response_status'=>200,'persistence_succeeded'=>true,'idempotent_replay'=>$replay,'attendance_saved'=>$response['attendance_saved'] ?? in_array($response['state'] ?? '',['RECORDED','ALREADY_MARKED'],true),'response_state'=>$response['state'] ?? '', 'enrollment_status'=>$response['enrollment_state'] ?? ($action==='enroll'?($response['state'] ?? ''):''),
            'comparison_source'=>$action==='enroll'?'SUBMITTED_CAPTURE':($action==='test_punch'?'RECOGNITION_RECEIPT':'CURRENT_OBSERVATIONS'),
            'capture_session'=>$response['capture_session'] ?? $this->auditContext['capture_session'] ?? '', 'reference_calibration_id'=>(int)($response['matched_reference_capture_id'] ?? $response['calibration_id'] ?? 0),'reference_gallery_sha256'=>isset($response['test_reference_ids'])?hash('sha256',json_encode($response['test_reference_ids'])):'','matched_member_id'=>(int)($response['duplicate_person_id'] ?? 0),'matched_calibration_id'=>(int)($response['duplicate_reference_capture_id'] ?? 0),'capture_saved_as_reference'=>$response['capture_saved_as_reference'] ?? null,
            'member_id'=>(int)($response['person_id'] ?? $this->auditContext['member_id'] ?? 0),'enrollment_id'=>(int)($response['enrollment_id'] ?? 0),
            'calibration_id'=>(int)($response['capture_calibration_id'] ?? $response['calibration_id'] ?? 0),'observation_count'=>(int)($response['observation_count'] ?? 0),'decision'=>$response['capture_decision'] ?? $response['diagnostics']['decision'] ?? $response['state'] ?? '',
            'reason'=>in_array($response['state'] ?? '',['DUPLICATE','REJECTED_DUPLICATE'],true)?($response['capture_decision'] ?? 'DUPLICATE_MATCH'):($response['reason'] ?? ''), 'candidate_count'=>(int)($response['diagnostics']['candidate_count'] ?? $response['duplicate_candidate_count'] ?? 0)];
        if($action==='enroll'){
            if(in_array($response['state'],['DUPLICATE','REJECTED_DUPLICATE'],true))$this->audit('enrollment_duplicate',$meta);
            elseif(in_array($response['state'],['PENDING','ACTIVE','ENROLLED','ALREADY_ENROLLED'],true)){$this->audit('enrollment_saved',$meta);if(!empty($response['test_ready']))$this->audit('enrollment_test_ready',$meta);if(!empty($this->auditContext['update_face']))$this->audit('enrollment_updated',$meta);}
            else $this->audit('enrollment_failed',$meta);
        }elseif($action==='attendance'){
            $decision=$response['diagnostics']['decision'] ?? '';
            $event=in_array($response['state'],['TEST_RECOGNIZED','RECORDED','ALREADY_MARKED'],true)?'recognition_matched':(in_array($decision,['AMBIGUOUS','CONFLICTING_OBSERVATIONS'],true)?'recognition_ambiguous':($response['state']==='UNKNOWN' || $response['state']==='TEST_UNKNOWN'?'recognition_unknown':'recognition_rejected'));
            $this->audit($event,$meta);
            if($response['state']==='TEST_RECOGNIZED')$this->audit('attendance_test_staged',$meta);
            if(!empty($this->policy['isolated_pilot']) && $response['state']==='RECORDED')$this->audit('attendance_test_saved',$meta);
            // Explicit operational-attendance write events (LIVE), distinct from the TEST pilot above.
            elseif($response['state']==='RECORDED')$this->audit('attendance_marked',$meta);
            elseif($response['state']==='ALREADY_MARKED')$this->audit('attendance_already_marked',$meta);
        }elseif($action==='confirm' || $action==='finalize'){
            foreach($response['results'] ?? [] as $r)if(in_array($r['state'] ?? '',['RECORDED','ALREADY_MARKED','PRESERVED'],true))
                $this->audit($r['state']==='RECORDED'?'attendance_marked':'attendance_already_marked',array_replace($meta,['member_id'=>(int)($r['person_id'] ?? 0),'response_state'=>$r['state'],'attendance_saved'=>$r['state']==='RECORDED','decision'=>$r['state']]));
        }elseif($action==='test_punch'){foreach($response['results'] ?? [] as $r)$this->audit(in_array($r['state'],['RECORDED','ALREADY_MARKED'],true)?'attendance_test_punched':'attendance_test_punch_failed',array_replace($meta,['member_id'=>$r['person_id'],'test_attendance_id'=>$r['test_attendance_id'] ?? 0,'attendance_saved'=>false,'test_attendance_saved'=>in_array($r['state'],['RECORDED','ALREADY_MARKED'],true),'decision'=>$r['state'],'reason'=>$r['reason'] ?? '']));}
        elseif($action==='open')$this->audit($response['state']==='OPEN'?'scan_started':'recognition_rejected',$meta+['scan_id'=>(int)($response['scan_id'] ?? 0)]);
        elseif($action==='close')$this->audit('scan_stopped',$meta);
    }
    public function run(string $action, array $payload): array
    {
        if (!in_array($action, ['enroll', 'delete','open','close','attendance','confirm','finalize','migrate','calibration_collect','calibration_delete','validate_capture','activate_pending','camera_event','test_punch'], true)) throw new InvalidArgumentException('Unknown face operation');
        $key = $payload['idempotency_key'] ?? '';
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) throw new InvalidArgumentException('Valid idempotency key required');
        $hash = hash('sha256', json_encode([$action, $this->branch, $payload], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $database = $this->one('SELECT DATABASE() AS name')['name'];
        $this->auditStarted=microtime(true);$context=$payload['evaluation_context'] ?? [];
        $this->auditContext=['tenant'=>$database,'branch_id'=>$this->branch,'actor_id'=>$this->actor,'request_id'=>$key,'operation'=>$action,'member_id'=>(int)($payload['person_id'] ?? 0),'batch_id'=>(int)($payload['batch_id'] ?? $context['batch_id'] ?? 0),'session_id'=>(int)($payload['attendance_session_id'] ?? $context['session_id'] ?? 0),'update_face'=>($payload['update_face'] ?? false)===true,'test_only'=>$this->mode()==='TEST' || !empty($this->policy['isolated_pilot'])];
        if($action==='enroll'){$this->audit('enrollment_started');if(($payload['update_face'] ?? false)===true)$this->audit('enrollment_update_started');}
        if($action==='test_punch')$this->audit('attendance_test_punch_attempted');
        $lock = 'browser-face:' . substr(hash('sha256', $database), 0, 45);
        if ((int)$this->one('SELECT GET_LOCK(?,10) AS acquired', [$lock])['acquired'] !== 1) throw new RuntimeException('Face service is busy; retry');
        try {
            $saved = $this->one('SELECT action,payload_hash,response FROM face_web_request WHERE actor_id=? AND request_key=?', [$this->actor, $key]);
            if ($saved) {
                if (!hash_equals($saved['payload_hash'], $hash) || $saved['action'] !== $action) throw new InvalidArgumentException('Idempotency key reused with different input');
                $response=json_decode($saved['response'], true, 512, JSON_THROW_ON_ERROR);
                if (!in_array($action,['attendance','confirm','finalize','test_punch'],true) || (($response['state'] ?? '') !== 'PENDING' && !($action==='finalize' && ($response['state'] ?? '')==='PARTIAL_FINALIZATION') && !($action==='test_punch' && ($response['state'] ?? '')==='PARTIAL_TEST_PUNCH'))) {$this->auditResult($action,$response,true);return $response;}
            }
            if($this->mode()==='DISABLED' && !in_array($action,['close','delete','calibration_delete','confirm'],true) && !($action==='attendance' && $saved))throw new InvalidArgumentException('FIELD_RELEASE_DISABLED');
            if($action==='attendance'){$this->scan($payload);$this->audit('recognition_attempted');}
            $count = $this->one('SELECT COUNT(*) AS count FROM face_web_request WHERE actor_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)', [$this->actor]);
            if (!$saved && (int)$count['count'] >= 30) throw new RuntimeException('Too many face requests; wait one minute');
            if(!$saved && $action==='attendance' && in_array($this->mode(),['SHADOW','TEST'],true)){
                $scan=$this->scan($payload);
                // Bound evaluation summaries; do not erase required request or
                // attendance audit history to make room for new diagnostics.
                $n=$this->one("SELECT COUNT(*) AS n FROM face_web_request WHERE action='attendance' AND JSON_UNQUOTE(JSON_EXTRACT(response,'$.state')) IN('SHADOW_EVALUATED','TEST_RECOGNIZED','TEST_UNKNOWN') AND JSON_EXTRACT(response,'$.diagnostics') IS NOT NULL AND CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(response,'$.shadow_context.batch_id')),JSON_UNQUOTE(JSON_EXTRACT(response,'$.test_context.batch_id'))) AS UNSIGNED)=?",[$scan['batch_id']]);
                if((int)$n['n']>=10000)return ['state'=>'SHADOW_LIMIT','message'=>'Face checks paused. Please contact the administrator.'];
            }
            // AttendanceDomainService owns its transaction. Reserve the request
            // hash first so a crash cannot allow key reuse with changed input.
            $transaction = !in_array($action,['attendance','confirm','finalize','test_punch'],true);
            if ($transaction) $this->db->begin_transaction();
            elseif (!$saved) $this->execute('INSERT INTO face_web_request(actor_id,request_key,action,payload_hash,response) VALUES(?,?,?,?,?)',
                [$this->actor,$key,$action,$hash,'{"state":"PENDING"}'])->close();
            try {
                switch ($action) {
                    case 'camera_event':
                        if(empty($this->policy['testing_evaluation']) && empty($this->policy['isolated_pilot']))throw new InvalidArgumentException('Camera diagnostics unavailable');
                        if(($payload['event'] ?? '')!=='camera_recovery')throw new InvalidArgumentException('Invalid camera event');
                        if(isset($payload['scan_id']))$this->scan($payload);else $this->person($payload['person_id'] ?? null);
                        $this->audit('camera_recovery',['reason'=>'BROWSER_RECOVERY']);$response=['state'=>'EVENT_RECORDED'];break;
                    case 'enroll': $response=$this->enroll($payload);break;
                    case 'migrate': $response=$this->migrate($payload,$key);break;
                    case 'calibration_collect': $response=$this->collectCalibration($payload);break;
                    case 'calibration_delete': $response=$this->deleteCalibration($payload);break;
                    case 'validate_capture': $response=$this->captureEnrollment($payload,true);break;
                    case 'activate_pending': $response=$this->activatePending();break;
                    case 'delete': $response=$this->delete($payload);break;
                    case 'open': $response=$this->open($payload);break;
                    case 'close': $response=$this->close($payload);break;
                    case 'attendance': $response=$this->attendance($payload,$key);break;
                    case 'confirm': $response=$this->confirm($payload,$key);break;
                    case 'test_punch': $response=$this->testPunch($payload,$key);break;
                    case 'finalize': $response=$this->finalize($payload,$key);break;
                }
                if($action==='attendance' && $this->mode()==='TEST'){$response['test_scan_id']=(int)$payload['scan_id'];if($response['state']==='TEST_RECOGNIZED')$response['test_receipt_key']=$key;}
                if($action==='attendance' && !empty($response['suggestion'])){
                    $response['suggestion']['receipt_key']=$key;$response['review_scan_id']=(int)$payload['scan_id'];
                }
                if(!empty($this->policy['isolated_pilot']) && isset($response['diagnostics']))$response['diagnostics']['request_key']=$key;
                if ($transaction) {
                    $this->execute('INSERT INTO face_web_request(actor_id,request_key,action,payload_hash,response) VALUES(?,?,?,?,?)',
                        [$this->actor,$key,$action,$hash,json_encode($response,JSON_THROW_ON_ERROR)])->close();
                    $this->db->commit();
                } else $this->execute('UPDATE face_web_request SET response=? WHERE actor_id=? AND request_key=?',
                    [json_encode($response,JSON_THROW_ON_ERROR),$this->actor,$key])->close();
                $this->auditResult($action,$response);
                return $response;
            } catch (Throwable $error) { if($transaction) $this->db->rollback(); throw $error; }
        } catch(Throwable $error){if($action==='test_punch')$this->audit('attendance_test_punch_failed',['reason'=>'REQUEST_FAILED']);if(in_array($action,['attendance','confirm','finalize'],true))$this->audit('attendance_failed',['response_status'=>$error instanceof InvalidArgumentException?400:503,'reason'=>$error instanceof mysqli_sql_exception?'DATABASE_ERROR':($error instanceof InvalidArgumentException?'INVALID_INPUT':'SERVICE_UNAVAILABLE'),'persistence_outcome'=>$error instanceof InvalidArgumentException?'NOT_WRITTEN':'UNKNOWN_RECONCILE_IDEMPOTENCY']);if($action==='enroll' && !empty($this->auditContext['update_face']))$this->audit('enrollment_update_failed',['reason'=>'REQUEST_FAILED']);$this->audit($action==='enroll'?'enrollment_failed':'backend_error',['response_status'=>$error instanceof InvalidArgumentException?400:503,'reason'=>$error instanceof mysqli_sql_exception?'DATABASE_ERROR':($error instanceof InvalidArgumentException?'INVALID_INPUT':'SERVICE_UNAVAILABLE')]+($error instanceof InvalidArgumentException?['persistence_succeeded'=>false]:['persistence_outcome'=>'UNKNOWN_RECONCILE_IDEMPOTENCY']));throw $error;
        } finally { $stmt=$this->execute('SELECT RELEASE_LOCK(?)', [$lock]);$stmt->get_result()->free();$stmt->close(); }
    }

    private function versions(array $payload): void
    {
        foreach (['model_version','preprocessing_version'] as $field)
            if (($payload[$field] ?? '') !== $this->contract[$field]) throw new InvalidArgumentException('Incompatible model or preprocessing version');
    }

    private function person($id): array
    {
        if (!is_int($id) || $id < 1) throw new InvalidArgumentException('Valid person identifier required');
        $row = $this->one('SELECT m.id,m.contact_id,CONCAT_WS(\' \',c.fname,c.lname) AS name FROM member m JOIN contact c ON c.id=m.contact_id WHERE m.id=? AND m.bid=? AND m.status=1 AND m.park=0 AND c.park=0', [$id, $this->branch]);
        if (!$row) throw new InvalidArgumentException('Person is not authorized in this branch');
        return $row;
    }

    private function enroll(array $payload): array
    {
        $this->versions($payload);
        $vector = self::vector($payload['template'] ?? null);
        $person = $this->person($payload['person_id'] ?? null);
        if($this->lifecycle)return $this->captureEnrollment($payload,false);
        if (!$this->activationAllowed()) {
            if (($payload['consent'] ?? false)!==true) return ['state'=>'CALIBRATION_REQUIRED','message'=>BrowserFacePolicy::SETUP_REQUIRED_MESSAGE];
            return $this->collectEnrollmentObservation($payload,$person);
        }
        // Never skip old/incompatible enrollments and thereby weaken duplicates.
        $incompatible = $this->one('SELECT id FROM face_template WHERE status=\'ACTIVE\' AND model_version_id<>? LIMIT 1', [$this->modelId]);
        if ($incompatible) return ['state'=>'INCOMPATIBLE_TEMPLATES','message'=>'Existing templates require an authorized migration or re-enrollment'];
        $existing = $this->one('SELECT id FROM face_template WHERE member_id=? AND status=\'ACTIVE\' LIMIT 1', [$person['id']]);
        if ($existing) return ['state'=>'ALREADY_ENROLLED','person_id'=>(int)$person['id'],'message'=>'This person already has a face enrollment'];
        $highest = null;
        foreach ($this->templates() as $template) {
            $score = self::cosine($vector, $template['vector']);
            if (!$highest || $score > $highest['score']) $highest = $template + ['score'=>$score];
        }
        if ($highest && $highest['score'] >= $this->policy['DUPLICATE_THRESHOLD']) {
            $disclosure = $this->duplicateDisclosure($highest);
            $branchSuffix = !empty($disclosure['duplicate_branch_name']) ? " at {$disclosure['duplicate_branch_name']}" : '';
            $personSuffix = !empty($disclosure['duplicate_person_name']) ? " to {$disclosure['duplicate_person_name']}{$branchSuffix}" : '';
            $response = ['state'=>'DUPLICATE','message'=>'Face already registered'.$personSuffix];
            $response += $disclosure;
            return $response;
        }
        $this->execute('INSERT INTO biometric_consent(member_id,guardian_contact_id,notice_version,purpose,consent_status,verification_method,actor_employee_id,consented_at) VALUES(?,NULL,\'browser-face-v1\',\'ATTENDANCE\',\'PROGRAM_AUTHORIZED\',\'OPERATOR_INITIATED\',?,NOW())',[$person['id'],$this->actor]);
        $summary = json_encode(['source'=>'BROWSER_AUTOMATIC','preprocessing_version'=>$this->contract['preprocessing_version'],'created_by_actor_id'=>$this->actor]);
        $this->execute('INSERT INTO face_template(member_id,model_version_id,encrypted_embedding,encryption_key_id,embedding_dimension,quality_summary,status) VALUES(?,?,?,?,128,?,\'ACTIVE\')',
            [$person['id'],$this->modelId,$this->cipher->encrypt($vector),$this->cipher->keyId(),$summary]);
        return ['state'=>'ENROLLED','person_id'=>(int)$person['id'],'person_name'=>$person['name'],'message'=>'Face enrolled'];
    }

    private function delete(array $payload): array
    {
        $person = $this->person($payload['person_id'] ?? null);
        // Erase biometric ciphertext rather than retaining it in revoked rows.
        $this->execute('UPDATE face_template SET status=\'REVOKED\',revoked_at=NOW(),encrypted_embedding=\'\' WHERE member_id=?',[$person['id']]);
        $this->execute('UPDATE biometric_consent SET consent_status=\'WITHDRAWN\',withdrawn_at=NOW() WHERE member_id=?',[$person['id']]);
        if($this->lifecycle){$this->execute("UPDATE face_web_enrollment SET state='RECAPTURE_REQUIRED',encrypted_template='',active_template_id=NULL,decision_reason='DELETED' WHERE member_id=? AND branch_id=?",[$person['id'],$this->branch]);$this->eraseEvidence((int)$person['id']);}
        return ['state'=>'DELETED','person_id'=>(int)$person['id'],'message'=>'Face enrollment deleted'];
    }

    private function captureEnrollment(array $payload,bool $validation): array
    {
        if(!$this->lifecycle)throw new RuntimeException('Lifecycle migration required');
        $this->versions($payload);$person=$this->person($payload['person_id'] ?? null);
        if(($payload['update_face'] ?? false)===true && empty($this->policy['testing_evaluation']))throw new InvalidArgumentException('Testing reference update is unavailable');
        if(isset($this->policy['operating_mode']) && !isset($payload['evaluation_context']))throw new InvalidArgumentException('Select the member in an authorized attendance batch before capture');
        if(($payload['consent'] ?? false)!==true)throw new InvalidArgumentException('Consent must be confirmed before capture');
        $session=$payload['capture_session'] ?? '';if(!is_string($session) || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$session))throw new InvalidArgumentException('Separate capture session required');
        $observations=$payload['observations'] ?? null;if(!is_array($observations))throw new InvalidArgumentException('Observation batch required');
        $template=BrowserFaceMath::aggregate($observations);$submitted=self::vector($payload['template'] ?? null);
        if(self::cosine($template,$submitted)<1-1e-6)throw new InvalidArgumentException('Capture template does not match observations');
        $this->auditContext['capture_session']=$session;
        $this->audit('capture_completed',['observation_count'=>count($observations)]);
        if($this->one('SELECT id FROM face_web_evidence WHERE actor_id=? AND capture_session=?',[$this->actor,$session]))throw new InvalidArgumentException('This capture was already saved. Reopen the camera for a new capture.');
        if(!in_array($payload['lighting'] ?? '',['measured-dark','measured-normal','measured-bright'],true))throw new InvalidArgumentException('Measured camera lighting required');
        $visit='automatic-date:'.$this->one('SELECT DATE(NOW()) AS day')['day'];
        $day=$this->one('SELECT dataset_split FROM face_web_calibration WHERE branch_id=? AND member_id=? AND visit=? AND deleted_at IS NULL AND expires_at>NOW() LIMIT 1',[$this->branch,$person['id'],$visit]);
        if($validation && $day && $day['dataset_split']==='calibration')throw new InvalidArgumentException('This face was captured today. Please check it at a later visit.');
        $count=$this->one("SELECT COUNT(DISTINCT visit) AS n FROM face_web_calibration WHERE branch_id=? AND member_id=? AND visit LIKE 'automatic-date:%' AND deleted_at IS NULL AND expires_at>NOW()",[$this->branch,$person['id']]);
        // Check face is observation-only. The split still follows actual visit
        // dates, so an unenrolled test person can supply unknown trials in both
        // calibration and validation without creating an enrollment candidate.
        $payload['template']=$template;$payload['visit']=$visit;$payload['split']=$day['dataset_split'] ?? ((int)$count['n']<2?'calibration':'validation');
        $saved=$this->collectCalibration($payload);$id=$saved['sample_id'];
        $evaluation=null;
        if(isset($payload['evaluation_context'])){
            $context=$payload['evaluation_context'];if(!is_array($context))throw new InvalidArgumentException('Valid attendance context required');
            $roster=$this->roster(['batch_id'=>$context['batch_id'] ?? null,'attendance_date'=>$context['attendance_date'] ?? null]);$scheduled=false;
            if(!array_filter($roster['persons'],fn($p)=>(int)$p['id']===(int)$person['id']))throw new InvalidArgumentException('Member is not eligible in the selected batch');
            foreach($roster['sessions'] as $scheduledSession)if((int)$scheduledSession['id']===($context['session_id'] ?? null) && $scheduledSession['start_time']===($context['selected_time'] ?? null))$scheduled=true;
            if(!$scheduled)throw new InvalidArgumentException('Scheduled attendance context required');
            $evaluation=json_encode(['batch_id'=>$context['batch_id'],'attendance_date'=>$context['attendance_date'],'session_id'=>$context['session_id'],'eligible_identities'=>array_map(fn($p)=>(string)$p['id'],$roster['persons']),'label_source'=>'AUTHORIZED_MEMBER_SELECTION'],JSON_THROW_ON_ERROR);
        }
        $this->execute('INSERT INTO face_web_evidence(calibration_id,branch_id,member_id,actor_id,capture_session,purpose,encrypted_observations,encryption_key_id,evaluation_context) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$this->branch,$person['id'],$this->actor,$session,$validation?'VALIDATION':'ENROLL',$this->cipher->encryptBytes(json_encode($observations,JSON_THROW_ON_ERROR)),$this->cipher->keyId(),$evaluation]);
        if($validation)return ['state'=>'VALIDATION_SAVED','person_id'=>(int)$person['id'],'contact_id'=>(int)$person['contact_id'],'message'=>'Face check saved. Attendance unchanged.'];
        // Record the new operator-confirmed grant in audit order. Comparing
        // second-resolution timestamps alone misclassifies a fresh capture
        // immediately following withdrawal as still withdrawn.
        $this->execute("INSERT INTO biometric_consent(member_id,guardian_contact_id,notice_version,purpose,consent_status,verification_method,actor_employee_id,consented_at) VALUES(?,NULL,'browser-face-v1','ATTENDANCE','PROGRAM_AUTHORIZED','OPERATOR_INITIATED',?,NOW())",[$person['id'],$this->actor]);
        $current=$this->one('SELECT *,(expires_at>NOW()) AS unexpired FROM face_web_enrollment WHERE member_id=?',[$person['id']]);
        $keepActive=$current && $current['state']==='ACTIVE' && $this->one("SELECT id FROM face_template WHERE id=? AND status='ACTIVE' AND model_version_id=?",[$current['active_template_id'],$this->modelId]);
        // Keep the original usable enrollment reference. Further captures are
        // evidence, not template adaptation; explicit recapture replaces it.
        $keepTraining=$current && $current['state']==='PENDING' && (int)$current['unexpired'] && $current['model_version']===$this->contract['model_version'] && $current['preprocessing_version']===$this->contract['preprocessing_version'] && $current['encryption_key_id']===$this->cipher->keyId() && $current['encrypted_template']!==''
            && $this->one('SELECT id FROM face_web_calibration WHERE id=? AND deleted_at IS NULL AND expires_at>NOW()',[$current['calibration_id']]);
        $this->audit('enrollment_save_attempted',['member_id'=>(int)$person['id'],'calibration_id'=>(int)$id]);
        if(!$keepActive && !$keepTraining){
            $this->execute("INSERT INTO face_web_enrollment(branch_id,member_id,actor_id,state,encrypted_template,encryption_key_id,model_version,preprocessing_version,calibration_id,capture_session,expires_at) VALUES(?,?,?,'PENDING',?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 DAY)) ON DUPLICATE KEY UPDATE branch_id=VALUES(branch_id),actor_id=VALUES(actor_id),state='PENDING',encrypted_template=VALUES(encrypted_template),encryption_key_id=VALUES(encryption_key_id),model_version=VALUES(model_version),preprocessing_version=VALUES(preprocessing_version),calibration_id=VALUES(calibration_id),capture_session=VALUES(capture_session),expires_at=VALUES(expires_at),consented_at=NOW(),active_template_id=NULL,conflict_member_id=NULL,decision_reason='',policy_sha256=NULL,activated_at=NULL",[$this->branch,$person['id'],$this->actor,$this->cipher->encrypt($template),$this->cipher->keyId(),$this->contract['model_version'],$this->contract['preprocessing_version'],$id,$session]);
        }
        $captureMetadata=['capture_calibration_id'=>(int)$id,'observation_count'=>count($observations),'duplicate_check_status'=>'DEFERRED_PRODUCTION_POLICY','capture_session'=>$session,'capture_saved_as_reference'=>true];
        $diagnostics=[];
        $checkDuplicates = ($this->policy['ready']
            || !empty($this->policy['testing_evaluation'])
            || !empty($this->policy['rollout_authorized'])
            || in_array($this->policy['operating_mode'] ?? '', ['SHADOW', 'TEST', 'LIVE'], true)
            || !empty($this->templates())
        ) && !$this->one("SELECT id FROM face_template WHERE status='ACTIVE' AND model_version_id<>? LIMIT 1",[$this->modelId]);

        if($checkDuplicates){
            // References are immutable, but each newly submitted capture must
            // still be checked across all tenant branches for biometric uniqueness.
            $candidates=$this->enrollmentDuplicateCandidates((int)$person['id']);
            $ranked=[];foreach($candidates as $candidate){$score=self::cosine($template,$candidate['vector']);$member=(int)$candidate['member_id'];if(!isset($ranked[$member]) || $score>$ranked[$member]['score'])$ranked[$member]=$candidate+['score'=>$score];}
            uasort($ranked,fn($a,$b)=>($b['score']<=>$a['score']) ?: ((int)$a['member_id']<=>(int)$b['member_id']));$best=$ranked?reset($ranked):null;
            $captureMetadata['duplicate_check_status']=$this->policy['ready']?'VALIDATED_CHECK_EXECUTED':(!empty($this->policy['testing_evaluation'])?'PROVISIONAL_TEST_CHECK_EXECUTED':(!empty($candidates)?'PROVISIONAL_CHECK_EXECUTED':'DEFERRED_PRODUCTION_POLICY'));
            $captureMetadata['duplicate_candidate_count']=count(array_unique(array_column($candidates,'member_id')));
            $duplicateThreshold=$this->policy['ready']?(float)$this->policy['DUPLICATE_THRESHOLD']:(float)($this->policy['TEST_DUPLICATE_THRESHOLD'] ?? 0.85);
            $conflicts=array_values(array_filter($ranked,fn($candidate)=>$candidate['score']>=$duplicateThreshold));
            $duplicate=count($conflicts)>0;$ambiguous=count($conflicts)>1;
            $captureMetadata['capture_decision']=$ambiguous?'AMBIGUOUS_DUPLICATE':($duplicate?'DUPLICATE_MATCH':'UNIQUE_CAPTURE');
            if(!empty($this->policy['testing_evaluation']))$captureMetadata['comparison_reference_ids']=array_values(array_unique(array_map(fn($t)=>(int)($t['test_capture_id'] ?? $t['calibration_id'] ?? 0),$candidates))); 
            if(!empty($this->policy['isolated_pilot']))$diagnostics=['diagnostics'=>['decision'=>$duplicate?'DUPLICATE_MATCH':'NO_DUPLICATE_MATCH','comparison_source'=>'SUBMITTED_CAPTURE','duplicate_check_ran'=>true,'candidate_count'=>count(array_unique(array_column($candidates,'member_id'))),'best_similarity'=>$best['score'] ?? null,'duplicate_threshold'=>$duplicateThreshold,'best_matching_person_id'=>$best && (int)$best['bid']===$this->branch?(int)$best['member_id']:null,'calibration_id'=>$id,'capture_session'=>$session,'preserved_reference_calibration_id'=>(int)(($keepActive || $keepTraining)?$current['calibration_id']:$id)]];
            if($duplicate){
                if(($payload['update_face'] ?? false)===true)$this->audit('enrollment_update_failed',['reason'=>$ambiguous?'AMBIGUOUS_DUPLICATE':'DUPLICATE_MATCH']);
                $this->execute("UPDATE face_web_evidence SET purpose='LABEL_CONFLICT' WHERE calibration_id=? AND branch_id=?",[$id,$this->branch]);
                // Reject this attempt without discarding a usable reference
                // previously captured for the selected member. New candidates
                // persist their rejection; no automatic activation runs here.
                if(!$keepActive && !$keepTraining)$this->execute("UPDATE face_web_enrollment SET state='REJECTED_DUPLICATE',decision_reason='ACTIVE_CONFLICT',conflict_member_id=?,policy_sha256=?,activated_at=NULL WHERE member_id=? AND branch_id=?",[$ambiguous?null:$best['member_id'],$this->policy['report_sha256'] ?? hash('sha256',json_encode($this->policy)),$person['id'],$this->branch]);
                $disclosure=$ambiguous?['duplicate_visible'=>false]:$this->duplicateDisclosure($best);
                if(!empty($disclosure['duplicate_visible']))$disclosure+=['duplicate_person_id'=>(int)$best['member_id'],'duplicate_reference_capture_id'=>(int)($best['test_capture_id'] ?? $best['calibration_id'] ?? 0)];
                $branchSuffix=!empty($disclosure['duplicate_branch_name'])?" at {$disclosure['duplicate_branch_name']}":'';
                $conflictMessage=!empty($disclosure['duplicate_visible']) && !empty($disclosure['duplicate_person_name'])
                    ?"Face already registered to {$disclosure['duplicate_person_name']}{$branchSuffix}"
                    :'This face belongs to another member.';
                return array_replace($this->enrollmentOutcome((int)$person['id']),['state'=>'REJECTED_DUPLICATE','message'=>$ambiguous?'Face could not be verified. Please try again.':$conflictMessage,'capture_saved_as_reference'=>false,'previous_reference_preserved'=>(bool)($keepActive || $keepTraining)])+$disclosure+$diagnostics+$captureMetadata;
            }
        }
        if($this->activationAllowed())$this->activatePending();
        return $this->enrollmentOutcome((int)$person['id'])+$diagnostics+$captureMetadata;
    }

    private function duplicateDisclosure(array $match): array
    {
        $memberId=(int)($match['member_id'] ?? 0);
        $person=$this->one("SELECT CONCAT_WS(' ',c.fname,c.lname) AS name,m.bid FROM member m JOIN contact c ON c.id=m.contact_id WHERE m.id=? AND m.status=1 AND m.park=0 AND c.park=0",[$memberId]);
        if(!$person)return ['duplicate_visible'=>false];
        $branchName='';
        try{
            $b=$this->one("SELECT branch FROM branch WHERE id=? AND park=0",[(int)$person['bid']]);
            $branchName=(string)($b['branch'] ?? '');
        }catch(Throwable $e){}
        $disclosure=['duplicate_visible'=>true,'duplicate_person_name'=>$person['name'],'duplicate_branch_id'=>(int)$person['bid']];
        if($branchName!==''){$disclosure['duplicate_branch_name']=$branchName;}
        return $disclosure;
    }

    private function enrollmentOutcome(int $member): array
    {
        $row=$this->one('SELECT e.*,m.contact_id FROM face_web_enrollment e JOIN member m ON m.id=e.member_id WHERE e.member_id=? AND e.branch_id=?',[$member,$this->branch]);
        $state=$row['state'] ?? 'PENDING';$messages=['PENDING'=>'Face captured. Enrollment will finish automatically when the system is ready.','ACTIVE'=>'Face enrolled','REJECTED_DUPLICATE'=>'This face belongs to another member.','RECAPTURE_REQUIRED'=>'Please capture this face again.'];
        if(($row['decision_reason'] ?? '')==='PILOT_NOT_APPROVED')$messages['PENDING']='Face captured. Only the approved test identity is enabled in this practice session.';
        $galleryConflict=!empty($this->policy['testing_evaluation']) && isset($this->testGalleryConflicts()[$member]);
        $testReady=!$galleryConflict && !empty($this->policy['testing_evaluation']) && in_array($state,['PENDING','ACTIVE'],true) && (bool)array_filter($this->shadowTemplates(),fn($r)=>(int)$r['member_id']===$member);
        if($state==='PENDING' && !empty($this->policy['testing_evaluation']))$messages['PENDING']=$galleryConflict?'Face saved. Enrolled faces need attention before recognition.':($testReady?'Face saved. Ready for testing.':'Face saved, but recognition is not ready. Please check the member enrollment.');
        return ['enrollment_id'=>(int)($row['id'] ?? 0),'calibration_id'=>(int)($row['calibration_id'] ?? 0),'persistence_succeeded'=>true,'test_only'=>!empty($this->policy['testing_evaluation']),'test_ready'=>$testReady,'test_gallery_conflict'=>$galleryConflict,'test_excluded'=>($row['decision_reason'] ?? '')==='LABEL_EXCLUDED','state'=>$state,'enrollment_state'=>$state,'reason'=>$row['decision_reason'] ?? '','person_id'=>$member,'contact_id'=>(int)($row['contact_id'] ?? 0),'message'=>$messages[$state] ?? 'Please capture this face again.'];
    }

    private function activatePending(): array
    {
        if(!$this->lifecycle)throw new RuntimeException('Lifecycle migration required');
        if(!$this->activationAllowed())return ['state'=>'ACTIVATION_PENDING','message'=>'Face captured. Attendance setup is pending.'];
        // Do not skip incompatible active populations. No decision is made.
        if($this->one("SELECT id FROM face_template WHERE status='ACTIVE' AND model_version_id<>? LIMIT 1",[$this->modelId]))return ['state'=>'ACTIVATION_PENDING','message'=>'Enrollment is awaiting an administrator.'];
        $rows=$this->rows("SELECT e.*,(e.expires_at>NOW()) AS unexpired,m.bid AS current_branch,m.status AS member_status,m.park,c.park AS contact_park,c.id AS contact_id FROM face_web_enrollment e JOIN member m ON m.id=e.member_id JOIN contact c ON c.id=m.contact_id WHERE e.state IN('PENDING','RECAPTURE_REQUIRED') ORDER BY e.id FOR UPDATE");
        $vectors=[];$invalid=[];
        foreach($rows as $row){
            try{if(!(int)$row['unexpired'] || (int)$row['current_branch']!==(int)$row['branch_id'] || (int)$row['member_status']!==1 || (int)$row['park'] || (int)$row['contact_park'] || empty($row['consented_at']) || $row['model_version']!==$this->contract['model_version'] || $row['preprocessing_version']!==$this->contract['preprocessing_version'] || $row['encryption_key_id']!==$this->cipher->keyId())throw new InvalidArgumentException('Incompatible candidate');
                if(!$this->consentApproved((int)$row['member_id'],true))throw new InvalidArgumentException('Consent withdrawn');
                if(!$this->one('SELECT id FROM face_web_calibration WHERE id=? AND deleted_at IS NULL AND expires_at>NOW()',[$row['calibration_id']]))throw new InvalidArgumentException('Evidence expired');
                $vectors[(int)$row['member_id']]=self::vector($this->cipher->decrypt($row['encrypted_template']));
            }catch(InvalidArgumentException $e){$invalid[(int)$row['member_id']]=true;}
        }
        $active=$this->templates();$outcomes=[];$hash=$this->policy['report_sha256'] ?? hash('sha256',json_encode($this->policy));
        $approvedMembers=!empty($this->policy['rollout_batch_id'])?array_map('intval',array_column($this->eligible((int)$this->policy['rollout_batch_id'],date('Y-m-d')),'id')):null;
        foreach($rows as $row){
            if((int)$row['branch_id']!==$this->branch || $row['state']!=='PENDING')continue;
            // Global candidates still participate in conflict checks, but
            // an approved batch cannot change another batch's lifecycle.
            if($approvedMembers!==null && !in_array((int)$row['member_id'],$approvedMembers,true))continue;
            $member=(int)$row['member_id'];$state='ACTIVE';$reason='VALIDATED_UNIQUE';$conflict=null;$templateId=null;
            if(isset($invalid[$member])){$state='RECAPTURE_REQUIRED';$reason='INCOMPATIBLE_OR_EXPIRED';}
            else{
                foreach($active as $other){if((int)$other['member_id']===$member){$templateId=(int)$this->one("SELECT id FROM face_template WHERE member_id=? AND status='ACTIVE' AND model_version_id=? LIMIT 1",[$member,$this->modelId])['id'];break;}
                    if(self::cosine($vectors[$member],$other['vector'])>=$this->policy['DUPLICATE_THRESHOLD']){$state='REJECTED_DUPLICATE';$reason='ACTIVE_CONFLICT';$conflict=(int)$other['member_id'];break;}}
                if($state==='ACTIVE' && !$templateId)foreach($vectors as $id=>$vector)if($id!==$member && self::cosine($vectors[$member],$vector)>=$this->policy['DUPLICATE_THRESHOLD']){$state='RECAPTURE_REQUIRED';$reason='PENDING_IDENTITY_CONFLICT';break;}
            }
            if($state==='ACTIVE' && !$templateId && isset($this->policy['activation_member_ids']) && !in_array($member,$this->policy['activation_member_ids'],true)){
                $state='PENDING';$reason='PILOT_NOT_APPROVED';
            }
            if($state==='ACTIVE' && !$templateId){
                $this->execute("INSERT INTO biometric_consent(member_id,guardian_contact_id,notice_version,purpose,consent_status,verification_method,actor_employee_id,consented_at) VALUES(?,NULL,'browser-face-v1','ATTENDANCE','PROGRAM_AUTHORIZED','OPERATOR_INITIATED',?,NOW())",[$member,$this->actor]);
                $summary=json_encode(['source'=>'BROWSER_AUTOMATIC','enrollment_id'=>(int)$row['id'],'created_by_actor_id'=>$this->actor,'policy_sha256'=>$hash]);
                $this->execute("INSERT INTO face_template(member_id,model_version_id,encrypted_embedding,encryption_key_id,embedding_dimension,quality_summary,status) VALUES(?,?,?,?,128,?,'ACTIVE')",[$member,$this->modelId,$this->cipher->encrypt($vectors[$member]),$this->cipher->keyId(),$summary]);$templateId=(int)$this->db->insert_id;
            }
            // Snapshot comparison above prevents order-dependent pending winners.
            if(in_array($reason,['ACTIVE_CONFLICT','PENDING_IDENTITY_CONFLICT'],true))$this->execute("UPDATE face_web_evidence SET purpose='LABEL_CONFLICT' WHERE calibration_id=? AND branch_id=?",[$row['calibration_id'],$this->branch]);
            $this->execute('UPDATE face_web_enrollment SET state=?,decision_reason=?,active_template_id=?,conflict_member_id=?,policy_sha256=?,activated_at=IF(?=\'ACTIVE\',NOW(),NULL) WHERE id=?',[$state,$reason,$templateId,$conflict,$hash,$state,$row['id']]);$outcomes[$member]=$state;
        }
        return ['state'=>'ACTIVATION_COMPLETE','outcomes'=>$outcomes,'message'=>'Saved faces checked.'];
    }

    private function eraseEvidence(int $member): void
    {
        $this->execute("UPDATE face_web_evidence SET encrypted_observations='' WHERE member_id=? AND branch_id=?",[$member,$this->branch]);
        $this->execute("UPDATE face_web_calibration SET encrypted_embedding='',deleted_at=NOW() WHERE member_id=? AND branch_id=? AND deleted_at IS NULL",[$member,$this->branch]);
    }

    public function enrollmentStatus(): array
    {
        if(!$this->lifecycle)return ['persons'=>[],'lifecycle_available'=>false];
        $rows=$this->rows('SELECT e.member_id,m.contact_id,e.state,e.active_template_id,e.calibration_id,e.decision_reason,(e.expires_at>NOW() AND LENGTH(e.encrypted_template)>0 AND e.encryption_key_id=? AND e.model_version=? AND e.preprocessing_version=? AND EXISTS(SELECT 1 FROM face_web_calibration c WHERE c.id=e.calibration_id AND c.deleted_at IS NULL AND c.expires_at>NOW())) AS usable FROM face_web_enrollment e JOIN member m ON m.id=e.member_id WHERE e.branch_id=?',[$this->cipher->keyId(),$this->contract['model_version'],$this->contract['preprocessing_version'],$this->branch]);
        $testMembers=!empty($this->policy['testing_evaluation'])?array_column($this->testTemplates(),'member_id'):[];
        $conflicts=!empty($this->policy['testing_evaluation'])?$this->testGalleryConflicts():[];
        $attempts=[];foreach($this->rows("SELECT r.response,r.created_at FROM face_web_request r JOIN face_web_enrollment e ON e.member_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.response,'$.person_id')) AS UNSIGNED) WHERE r.action='enroll' AND e.branch_id=? ORDER BY CAST(JSON_UNQUOTE(JSON_EXTRACT(r.response,'$.capture_calibration_id')) AS UNSIGNED) DESC,r.created_at DESC,r.request_key DESC",[$this->branch]) as $request){$latest=json_decode($request['response'],true);$id=(int)($latest['person_id'] ?? 0);if(!isset($attempts[$id]))$attempts[$id]=[$latest,$request['created_at']];}
        foreach($rows as &$row){
            $row['test_excluded']=($row['decision_reason'] ?? '')==='LABEL_EXCLUDED';
            $row['test_gallery_conflict']=isset($conflicts[(int)$row['member_id']]);
            $row['test_ready']=!$row['test_gallery_conflict'] && in_array((int)$row['member_id'],$testMembers,true);
            [$latest,$time]=$attempts[(int)$row['member_id']] ?? [[],null];$row['latest_attempt_state']=$latest['state'] ?? null;$row['latest_attempt_capture_id']=(int)($latest['capture_calibration_id'] ?? 0);$row['latest_attempt_at']=$time;
            if(($row['state']==='PENDING' && !(int)$row['usable']) || ($row['state']==='ACTIVE' && !$this->one("SELECT id FROM face_template WHERE id=? AND status='ACTIVE' AND model_version_id=?",[$row['active_template_id'],$this->modelId])))$row['state']='RECAPTURE_REQUIRED';
            unset($row['usable']);
        }unset($row);
        return ['persons'=>$rows,'lifecycle_available'=>true];
    }

    private function collectEnrollmentObservation(array $payload,array $person): array
    {
        if(!in_array($payload['lighting'] ?? '',['measured-dark','measured-normal','measured-bright'],true))throw new InvalidArgumentException('Measured camera lighting required');
        $visit='automatic-date:'.$this->one('SELECT DATE(NOW()) AS day')['day'];
        $existing=$this->one('SELECT dataset_split FROM face_web_calibration WHERE branch_id=? AND member_id=? AND visit=? AND deleted_at IS NULL AND expires_at>NOW() AND model_version=? AND preprocessing_version=? LIMIT 1',[$this->branch,$person['id'],$visit,$this->contract['model_version'],$this->contract['preprocessing_version']]);
        $count=$this->one('SELECT COUNT(DISTINCT visit) AS visits FROM face_web_calibration WHERE branch_id=? AND member_id=? AND visit LIKE \'automatic-date:%\' AND deleted_at IS NULL AND expires_at>NOW() AND model_version=? AND preprocessing_version=?',[$this->branch,$person['id'],$this->contract['model_version'],$this->contract['preprocessing_version']]);
        $payload['visit']=$visit;$payload['split']=$existing['dataset_split'] ?? ((int)$count['visits']<2?'calibration':'validation');
        $saved=$this->collectCalibration($payload);
        return ['state'=>'ENROLLMENT_PENDING','person_id'=>(int)$person['id'],'contact_id'=>(int)$person['contact_id'],'sample_id'=>$saved['sample_id'],'message'=>'Face sample saved. Enrollment is pending matching setup; no attendance was marked.'];
    }

    private function templates(bool $attendanceOnly=false): array
    {
        $rows = $this->rows('SELECT ft.member_id,ft.encrypted_embedding,ft.encryption_key_id,m.bid,CONCAT_WS(\' \',c.fname,c.lname) AS name FROM face_template ft JOIN member m ON m.id=ft.member_id JOIN contact c ON c.id=m.contact_id WHERE ft.status=\'ACTIVE\' AND ft.model_version_id=?',[$this->modelId]);
        if($attendanceOnly && isset($this->policy['matching_member_ids']))$rows=array_filter($rows,fn($row)=>in_array((int)$row['member_id'],$this->policy['matching_member_ids'],true));
        foreach ($rows as &$row) {
            if ($row['encryption_key_id'] !== $this->cipher->keyId()) throw new RuntimeException('Face template encryption key mismatch');
            $row['vector'] = self::vector($this->cipher->decrypt($row['encrypted_embedding']));
            unset($row['encrypted_embedding']);
        }
        return $rows;
    }

    public function options(): array
    {
        $data=['persons'=>$this->rows('SELECT m.id,m.contact_id,CONCAT_WS(\' \',c.fname,c.lname) AS name,(SELECT COUNT(*) FROM face_template ft WHERE ft.member_id=m.id AND ft.status=\'ACTIVE\') AS enrolled,(SELECT COUNT(*) FROM face_web_calibration fc WHERE fc.member_id=m.id AND fc.branch_id=m.bid AND fc.deleted_at IS NULL AND fc.expires_at>NOW() AND fc.model_version=? AND fc.preprocessing_version=?) AS pending_samples FROM member m JOIN contact c ON c.id=m.contact_id WHERE m.bid=? AND m.status=1 AND m.park=0 AND c.park=0 ORDER BY c.fname,c.lname',[$this->contract['model_version'],$this->contract['preprocessing_version'],$this->branch]),
            'batches'=>$this->rows('SELECT id,batch AS name FROM batch WHERE bid=? AND park=0 AND inactive=0 ORDER BY batch',[$this->branch])];
        $states=[];foreach($this->enrollmentStatus()['persons'] as $row)$states[(int)$row['member_id']]=$row;
        foreach($data['persons'] as &$person){$person['enrollment_state']=$states[(int)$person['id']]['state'] ?? ((int)$person['enrolled']>0?'ACTIVE':null);$person['test_ready']=$states[(int)$person['id']]['test_ready'] ?? false;$person['test_gallery_conflict']=$states[(int)$person['id']]['test_gallery_conflict'] ?? false;$person['test_excluded']=$states[(int)$person['id']]['test_excluded'] ?? false;$person['latest_attempt_state']=$states[(int)$person['id']]['latest_attempt_state'] ?? null;$person['latest_attempt_capture_id']=$states[(int)$person['id']]['latest_attempt_capture_id'] ?? 0;$person['capture_authorized']=($this->policy['capture_available'] ?? true);}unset($person);return $data;
    }

    private function date($date): string
    {
        if (!is_string($date)) throw new InvalidArgumentException('Attendance date required');
        $parsed=DateTime::createFromFormat('!Y-m-d',$date);
        if (!$parsed || $parsed->format('Y-m-d')!==$date || substr($date,0,7)!==date('Y-m')) throw new InvalidArgumentException('Attendance date must be in the current month');
        return $date;
    }

    public function roster(array $payload): array
    {
        $batch=$payload['batch_id'] ?? 0;
        if (!is_int($batch) || $batch < 1) throw new InvalidArgumentException('Batch required');
        $date=$this->date($payload['attendance_date'] ?? null);
        if (!$this->one('SELECT id FROM batch WHERE id=? AND bid=? AND park=0 AND inactive=0 AND start_date<=? AND end_date>=?',[$batch,$this->branch,$date,$date])) throw new InvalidArgumentException('Batch is not authorized on this date');
        $sessions=$this->rows('SELECT id,start_time,end_time FROM session WHERE batch_id=? AND date=? AND park=0 ORDER BY start_time',[$batch,$date]);
        if (!$sessions && !$this->one('SELECT id FROM session WHERE batch_id=? AND park=0 LIMIT 1',[$batch]))
            $sessions=$this->rows('SELECT 0 AS id,start_time,end_time FROM batch_time WHERE batch_id=? AND day_code=? AND park=0 ORDER BY start_time',[$batch,date('w',strtotime($date))]);
        return ['sessions'=>$sessions,'persons'=>$this->eligible($batch,$date)];
    }

    private function eligible(int $batch,string $date): array
    {
        return $this->rows('SELECT DISTINCT m.id,m.contact_id,i.sessions,i.sessions_used,CONCAT_WS(\' \',c.fname,c.lname) AS name,(SELECT COUNT(*) FROM face_template ft WHERE ft.member_id=m.id AND ft.status=\'ACTIVE\' AND ft.model_version_id=?) AS enrolled FROM member m JOIN contact c ON c.id=m.contact_id JOIN invoice_invoiceitem_module_view i ON i.contact_id=m.contact_id AND i.batch_id=? WHERE m.bid=? AND m.status=1 AND m.park=0 AND c.park=0 AND i.park=0 AND i.item_park=0 AND i.start_date<=? AND i.end_date>=? AND (i.sessions=0 OR i.sessions>i.sessions_used OR EXISTS(SELECT 1 FROM attendance_event ae WHERE ae.person_id=m.contact_id AND ae.person_type=\'M\' AND ae.batch_id=? AND ae.attendance_date=? AND ae.event_type=\'PUNCH_IN\'))',
            [$this->modelId,$batch,$this->branch,$date,$date,$batch,$date]);
    }

    private function consentApproved(int $member,bool $capturedConsent=false): bool
    {
        $row=$this->one('SELECT consent_status,withdrawn_at FROM biometric_consent WHERE member_id=? ORDER BY consented_at DESC,id DESC LIMIT 1',[$member]);
        // Older pending captures recorded explicit consent in the enrollment
        // row before this audit grant existed. A recorded withdrawal still
        // overrides that fallback; active templates always need a grant.
        return $row ? $row['consent_status']==='PROGRAM_AUTHORIZED' && $row['withdrawn_at']===null : $capturedConsent;
    }
    private function shadowTemplates(): array
    {
        $templates=array_values(array_filter($this->templates(true),function($r){
            if((int)$r['bid']!==$this->branch)return false;
            return $this->consentApproved((int)$r['member_id']);
        }));
        if(!$this->lifecycle)return $templates;
        $rows=$this->rows("SELECT e.member_id,e.encrypted_template,e.encryption_key_id,e.id AS enrollment_id,e.calibration_id FROM face_web_enrollment e JOIN member m ON m.id=e.member_id JOIN face_web_calibration c ON c.id=e.calibration_id LEFT JOIN face_web_evidence v ON v.calibration_id=c.id WHERE e.branch_id=? AND m.bid=e.branch_id AND m.status=1 AND m.park=0 AND e.state IN('PENDING','ACTIVE') AND e.expires_at>NOW() AND e.model_version=? AND e.preprocessing_version=? AND c.model_version=e.model_version AND c.preprocessing_version=e.preprocessing_version AND c.encryption_key_id=e.encryption_key_id AND c.deleted_at IS NULL AND c.expires_at>NOW() AND (v.purpose='ENROLL' OR (v.calibration_id IS NULL AND e.decision_reason='RECOVERED_ENROLLMENT_CAPTURE' AND c.dataset_split='calibration')) AND LENGTH(e.encrypted_template)>0 AND e.consented_at IS NOT NULL",[$this->branch,$this->contract['model_version'],$this->contract['preprocessing_version']]);
        foreach($rows as $row){
            if($row['encryption_key_id']!==$this->cipher->keyId() || !$this->consentApproved((int)$row['member_id'],true))continue;
            $templates[]=['member_id'=>(int)$row['member_id'],'vector'=>self::vector($this->cipher->decrypt($row['encrypted_template'])),'bid'=>$this->branch,'enrollment_id'=>(int)$row['enrollment_id'],'calibration_id'=>(int)$row['calibration_id']];
        }
        return $templates;
    }

    private function enrollmentDuplicateCandidates(int $excludeMemberId): array
    {
        $candidates = array_values(array_filter($this->templates(), fn($t) => (int)$t['member_id'] !== $excludeMemberId));
        if (!$this->lifecycle) return $candidates;

        $allowPendingCandidates = !empty($this->policy['testing_evaluation'])
            || !empty($this->policy['rollout_authorized'])
            || in_array($this->policy['operating_mode'] ?? '', ['SHADOW', 'TEST', 'LIVE'], true);
        if (!$allowPendingCandidates) return $candidates;

        $rows = $this->rows(
            "SELECT e.member_id, e.encrypted_template, e.encryption_key_id, e.id AS enrollment_id, e.calibration_id, m.bid " .
            "FROM face_web_enrollment e " .
            "JOIN member m ON m.id = e.member_id " .
            "JOIN face_web_calibration c ON c.id = e.calibration_id " .
            "LEFT JOIN face_web_evidence v ON v.calibration_id = c.id " .
            "WHERE m.status = 1 AND m.park = 0 " .
            "AND e.state IN ('PENDING', 'ACTIVE') AND e.expires_at > NOW() " .
            "AND e.model_version = ? AND e.preprocessing_version = ? " .
            "AND c.model_version = e.model_version AND c.preprocessing_version = e.preprocessing_version " .
            "AND c.encryption_key_id = e.encryption_key_id AND c.deleted_at IS NULL AND c.expires_at > NOW() " .
            "AND (v.purpose = 'ENROLL' OR (v.calibration_id IS NULL AND e.decision_reason = 'RECOVERED_ENROLLMENT_CAPTURE' AND c.dataset_split = 'calibration')) " .
            "AND LENGTH(e.encrypted_template) > 0 AND e.consented_at IS NOT NULL " .
            "AND e.member_id <> ?",
            [$this->contract['model_version'], $this->contract['preprocessing_version'], $excludeMemberId]
        );

        $existing = array_flip(array_column($candidates, 'member_id'));
        foreach ($rows as $row) {
            $mid = (int)$row['member_id'];
            if (isset($existing[$mid])) continue;
            if ($row['encryption_key_id'] !== $this->cipher->keyId() || !$this->consentApproved($mid, true)) continue;
            try {
                $vec = self::vector($this->cipher->decrypt($row['encrypted_template']));
            } catch (Throwable $e) {
                continue;
            }
            $candidates[] = [
                'member_id' => $mid,
                'vector' => $vec,
                'bid' => (int)$row['bid'],
                'enrollment_id' => (int)$row['enrollment_id'],
                'calibration_id' => (int)$row['calibration_id'],
            ];
            $existing[$mid] = true;
        }

        foreach ($candidates as &$candidate) {
            $id = (int)$candidate['member_id'];
            $bid = (int)($candidate['bid'] ?? $this->branch);
            $r = $this->one(
                "SELECT c.id, c.encrypted_embedding FROM face_web_enrollment e " .
                "JOIN face_web_calibration c ON c.member_id = e.member_id AND c.branch_id = e.branch_id " .
                "JOIN face_web_evidence v ON v.calibration_id = c.id " .
                "WHERE e.member_id = ? AND e.branch_id = ? AND e.state IN ('PENDING', 'ACTIVE') " .
                "AND c.deleted_at IS NULL AND c.expires_at > NOW() AND v.purpose = 'ENROLL' " .
                "AND c.model_version = ? AND c.preprocessing_version = ? AND c.encryption_key_id = ? " .
                "ORDER BY c.id DESC LIMIT 1",
                [$id, $bid, $this->contract['model_version'], $this->contract['preprocessing_version'], $this->cipher->keyId()]
            );
            if ($r) {
                try {
                    $candidate['vector'] = self::vector($this->cipher->decrypt($r['encrypted_embedding']));
                    $candidate['test_capture_id'] = (int)$r['id'];
                } catch (Throwable $e) {}
            }
        }
        unset($candidate);

        return $candidates;
    }

    private function testTemplates(): array
    {
        $templates=$this->shadowTemplates();
        // TEST alone selects one latest successful authorized ENROLL capture.
        // ACTIVE/original production references are never modified.
        foreach($templates as &$template){
            $id=(int)$template['member_id'];
            $r=$this->one("SELECT c.id,c.encrypted_embedding FROM face_web_enrollment e JOIN face_web_calibration c ON c.member_id=e.member_id AND c.branch_id=e.branch_id JOIN face_web_evidence v ON v.calibration_id=c.id WHERE e.member_id=? AND e.branch_id=? AND e.state IN('PENDING','ACTIVE') AND c.deleted_at IS NULL AND c.expires_at>NOW() AND v.purpose='ENROLL' AND c.model_version=? AND c.preprocessing_version=? AND c.encryption_key_id=? ORDER BY c.id DESC LIMIT 1",[$id,$this->branch,$this->contract['model_version'],$this->contract['preprocessing_version'],$this->cipher->keyId()]);
            if($r){$template['vector']=self::vector($this->cipher->decrypt($r['encrypted_embedding']));$template['test_capture_id']=(int)$r['id'];}
        }unset($template);return $templates;
    }
    // A score conflict is not an identity label. Keep the originals in duplicate
    // protection but never accept a disputed label in TEST recognition.
    private function testGalleryConflicts(): array
    {
        $refs=array_merge($this->shadowTemplates(),$this->testTemplates());$conflicts=[];
        foreach($refs as $i=>$a)foreach(array_slice($refs,$i+1) as $b){
            if((int)$a['member_id']===(int)$b['member_id'])continue;
            if(self::cosine($a['vector'],$b['vector']) >= $this->policy['TEST_DUPLICATE_THRESHOLD']){$conflicts[(int)$a['member_id']]=true;$conflicts[(int)$b['member_id']]=true;}
        }return $conflicts;
    }

    private function shadow(array $payload,array $scan): array
    {
        $eligible=$this->eligible((int)$scan['batch_id'],$scan['attendance_date']);$people=[];
        foreach($eligible as $row)if($this->one("SELECT id FROM attendance_scan_roster WHERE scan_id=? AND member_id=? AND eligibility_status='ELIGIBLE'",[$scan['id'],$row['id']]))$people[(int)$row['id']]=$row;
        $templates=array_values(array_filter($this->mode()==='TEST'?$this->testTemplates():$this->shadowTemplates(),fn($r)=>isset($people[(int)$r['member_id']])));
        $observations=$payload['observations'];if(count($observations)<3 || count($observations)>5)throw new InvalidArgumentException('Three to five observations required');
        $observations=array_map([self::class,'vector'],$observations);$top=[];
        foreach($observations as $vector){$scores=[];foreach($templates as $t){$id=(int)$t['member_id'];$scores[$id]=max($scores[$id] ?? -1,self::cosine($vector,$t['vector']));}arsort($scores,SORT_NUMERIC);$candidates=[];foreach(array_slice($scores,0,2,true) as $id=>$score)$candidates[]=['member_id'=>$id,'similarity'=>$score];$top[]=$candidates;}
        $threshold = (float)($this->policy['ATTENDANCE_THRESHOLD'] ?? $this->policy['TEST_ATTENDANCE_THRESHOLD'] ?? 0.65);
        $diagnostics = [];
        $person = self::matchObservations($observations, $templates, $threshold, $diagnostics);
        if ($person !== null && isset($people[$person])) {
            return [
                'state' => 'RECOGNIZED',
                'person_id' => $person,
                'contact_id' => (int)$people[$person]['contact_id'],
                'person_name' => $people[$person]['name'],
                'message' => 'Recognized: ' . $people[$person]['name'],
                'attendance_saved' => false,
                'shadow_context' => [
                    'batch_id' => (int)$scan['batch_id'],
                    'branch_id' => $this->branch,
                    'session_id' => (int)$scan['session_id'],
                    'attendance_date' => $scan['attendance_date']
                ],
                'diagnostics' => $diagnostics + [
                    'candidate_count' => count(array_unique(array_column($templates, 'member_id'))),
                    'observation_count' => count($observations),
                    'top_candidates' => $top,
                    'matched_member_id' => $person
                ]
            ];
        }

        return [
            'state' => 'UNKNOWN',
            'person_id' => null,
            'contact_id' => null,
            'person_name' => null,
            'message' => 'Face not recognized. Please try again.',
            'reason' => $diagnostics['decision'] ?? 'BELOW_CUTOFF',
            'shadow_context' => [
                'batch_id' => (int)$scan['batch_id'],
                'branch_id' => $this->branch,
                'session_id' => (int)$scan['session_id'],
                'attendance_date' => $scan['attendance_date']
            ],
            'diagnostics' => $diagnostics + [
                'candidate_count' => count(array_unique(array_column($templates, 'member_id'))),
                'observation_count' => count($observations),
                'top_candidates' => $top,
                'matched_member_id' => null
            ]
        ];
    }

    private function open(array $payload): array
    {
        $mode=$this->mode();
        if($mode==='CAPTURE_ONLY')return ['state'=>'CAPTURE_ONLY','message'=>'Face capture is available. Scanning is not enabled for this class yet.'];
        if($mode==='LIVE' && !$this->activationAllowed())return ['state'=>'CALIBRATION_REQUIRED','message'=>BrowserFacePolicy::SETUP_REQUIRED_MESSAGE];
        $roster=$this->roster($payload);
        $session=$payload['attendance_session_id'] ?? null;$time=$payload['selected_time'] ?? null;
        if (!is_int($session) || $session<0 || !is_string($time)) throw new InvalidArgumentException('Valid session and time required');
        $valid=false;
        foreach($roster['sessions'] as $row) if((int)$row['id']===$session && $row['start_time']===$time) $valid=true;
        if (!$valid) throw new InvalidArgumentException('Attendance session is not scheduled in this context');
        if($this->lifecycle && $mode==='LIVE')$this->activatePending();
        if(in_array($mode,['SHADOW','TEST'],true)){
            $refs=$this->shadowTemplates();$counts=array_count_values(array_column($refs,'member_id'));
            foreach($roster['persons'] as &$row)$row['enrolled']=$counts[(int)$row['id']] ?? 0;unset($row);
        }else $roster=$this->roster($payload);
        if (!array_filter($roster['persons'],fn($row)=>(int)$row['enrolled']>0)) return ['state'=>'NO_ENROLLMENTS','message'=>'Capture a face for an eligible member before scanning.'];
        $token=bin2hex(random_bytes(32));$version=hash('sha256',json_encode($roster['persons']));
        $binding=$this->scanBinding();
        $this->execute('INSERT INTO attendance_scan(scan_token,branch_id,batch_id,attendance_date,session_id,event_type,actor_employee_id,roster_version,provider_id,provider_version,model_version_id,calibration_policy_version,status,expires_at) VALUES(?,?,?,?,?,\'PUNCH_IN\',?,?,\'browser-onnx\',\'1\',?,?,\'ACTIVE\',DATE_ADD(NOW(),INTERVAL 2 HOUR))',
            [$token,$this->branch,$payload['batch_id'],$payload['attendance_date'],$session,$this->actor,$version,$this->modelId,$binding])->close();
        $scan=(int)$this->db->insert_id;
        foreach($roster['persons'] as $row) $this->execute('INSERT INTO attendance_scan_roster(scan_id,member_id,candidate_ref,eligibility_status,consent_status,template_count) VALUES(?,?,?,\'ELIGIBLE\',\'PROGRAM_AUTHORIZED\',?)',
            [$scan,$row['id'],bin2hex(random_bytes(16)),$row['enrolled']])->close();
        $scheduled=array_values(array_filter($roster['sessions'],fn($r)=>(int)$r['id']===$session))[0];
        $cutoff=$this->cutoff($payload['attendance_date'],$scheduled);
        return ['state'=>'OPEN','operating_mode'=>$mode,'scan_id'=>$scan,'scan_token'=>$token,'finalization_enabled'=>!empty($this->policy['finalize_absences']) && empty($this->policy['isolated_pilot']),'cutoff_at'=>$cutoff?->format(DATE_ATOM),'enrollments'=>$this->enrollmentStatus()['persons'],'message'=>$mode==='SHADOW'?'Face checks only. Attendance stays unchanged.':'Attendance session opened'];
    }

    private function scan(array $payload): array
    {
        $id=$payload['scan_id'] ?? null;$token=$payload['scan_token'] ?? '';
        if(!is_int($id) || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new InvalidArgumentException('Valid attendance session required');
        $scan=$this->one('SELECT attendance_scan.*,(expires_at>NOW()) AS unexpired FROM attendance_scan WHERE id=? AND scan_token=? AND actor_employee_id=? AND branch_id=? AND provider_id=\'browser-onnx\' AND model_version_id=?',[$id,$token,$this->actor,$this->branch,$this->modelId]);
        if(!$scan) throw new InvalidArgumentException('Attendance session is not authorized');
        $this->auditContext=array_replace($this->auditContext,['scan_id'=>(int)$scan['id'],'batch_id'=>(int)$scan['batch_id'],'session_id'=>(int)$scan['session_id']]);
        return $scan;
    }

    private function close(array $payload): array
    {
        $scan=$this->scan($payload);
        $this->execute('UPDATE attendance_scan SET status=\'COMPLETED\',completed_at=NOW() WHERE id=? AND status<>\'FINALIZED\'',[$scan['id']])->close();
        return ['state'=>'CLOSED','message'=>'Camera session closed; saved attendance remains'];
    }

    public static function matchObservations(array $observations,array $templates,float $threshold,?array &$diagnostics=null): ?int
    {
        if(count($observations)<3 || count($observations)>5) throw new InvalidArgumentException('Three to five observations required');
        $winner=null;
        $observations=array_map([self::class,'vector'],$observations);
        $diagnostics=['observation_count'=>count($observations),'candidate_count'=>count(array_unique(array_column($templates,'member_id'))),'decision'=>'MATCHED','lowest_top_similarity'=>null,'highest_top_similarity'=>null];
        foreach($observations as $vector) {
            $scores=[];
            foreach($templates as $row) {
                $id=(int)$row['member_id'];$score=self::cosine($vector,$row['vector']);
                $scores[$id]=max($scores[$id] ?? -1,$score);
            }
            $accepted=array_keys(array_filter($scores,fn($score)=>$score >= $threshold));
            if($scores){$top=max($scores);$diagnostics['lowest_top_similarity']=$diagnostics['lowest_top_similarity']===null?$top:min($top,$diagnostics['lowest_top_similarity']);$diagnostics['highest_top_similarity']=$diagnostics['highest_top_similarity']===null?$top:max($top,$diagnostics['highest_top_similarity']);}
            if(count($accepted)!==1 || ($winner!==null && $winner!==$accepted[0])){
                $diagnostics['decision']=!$scores?'NO_ELIGIBLE_REFERENCE':(!$accepted?'BELOW_CUTOFF':(count($accepted)>1?'AMBIGUOUS':'CONFLICTING_OBSERVATIONS'));return null;
            }
            $winner=$accepted[0];
        }
        return $winner;
    }

    private function attendance(array $payload,string $key): array
    {
        $this->versions($payload);$scan=$this->scan($payload);
        if(!is_array($payload['observations'] ?? null)) throw new InvalidArgumentException('Observation batch required');
        // Recover a committed domain write even if its HTTP/journal response
        // was lost and the camera lease subsequently closed. No new decision.
        $recovered=$this->one("SELECT a.id,a.negative,m.id AS member_id,m.contact_id,CONCAT_WS(' ',c.fname,c.lname) AS name FROM attendance_event e JOIN attendance a ON a.id=e.attendance_id JOIN member m ON m.contact_id=e.person_id JOIN contact c ON c.id=m.contact_id WHERE e.client_attempt_id=? AND e.scan_id=? AND e.actor_employee_id=? AND e.branch_id=? AND e.batch_id=? AND e.session_id=? AND e.attendance_date=? AND e.source='FACE_MATCH' AND e.event_type='PUNCH_IN' AND m.bid=? AND a.park=0",['face_'.hash('sha256',$key),$scan['id'],$this->actor,$this->branch,$scan['batch_id'],$scan['session_id'],$scan['attendance_date'],$this->branch]);
        if($recovered)return ['state'=>(int)$recovered['negative']===0?'ALREADY_MARKED':'REJECTED','person_id'=>(int)$recovered['member_id'],'contact_id'=>(int)$recovered['contact_id'],'person_name'=>$recovered['name'],'attendance_id'=>(int)$recovered['id'],'deducted_sessions'=>0];
        if($this->mode()==='DISABLED')throw new InvalidArgumentException('FIELD_RELEASE_DISABLED');
        if($scan['status']!=='ACTIVE' || !(int)$scan['unexpired']) throw new InvalidArgumentException('Attendance camera session has ended');
        $mode=$this->mode();$binding=$scan['calibration_policy_version'];
        if($binding!==$this->scanBinding())return ['state'=>'SCAN_MODE_CHANGED','message'=>'Face scanning changed. Please reopen the scanner.'];
        if(in_array($mode,['SHADOW','TEST'],true))return $this->shadow($payload,$scan);
        if($mode!=='LIVE')return ['state'=>'CAPTURE_ONLY','message'=>'Face capture is available. Scanning is not enabled for this class yet.'];
        if(!$this->activationAllowed()) return ['state'=>'CALIBRATION_REQUIRED','message'=>BrowserFacePolicy::SETUP_REQUIRED_MESSAGE];
        if($this->sessionCancelled($scan))return ['state'=>'REJECTED','reason'=>'SESSION_CANCELLED','message'=>'This attendance session is unavailable.'];
        if(!empty($this->policy['finalize_absences']) && empty($this->policy['isolated_pilot'])){
            $scheduled=$this->roster(['batch_id'=>(int)$scan['batch_id'],'attendance_date'=>$scan['attendance_date']]);
            foreach($scheduled['sessions'] as $s)if((int)$s['id']===(int)$scan['session_id'] && ($end=$this->cutoff($scan['attendance_date'],$s)) && new DateTimeImmutable('now',$end->getTimezone())>=$end)return ['state'=>'SESSION_CUTOFF','message'=>'This attendance session has ended.'];
        }
        $matchingStarted=microtime(true);$eligible=$this->eligible((int)$scan['batch_id'],$scan['attendance_date']);$people=[];
        foreach($eligible as $row) if($this->consentApproved((int)$row['id']) && $this->one('SELECT r.id FROM attendance_scan_roster r WHERE r.scan_id=? AND r.member_id=? AND r.eligibility_status=\'ELIGIBLE\'',[$scan['id'],$row['id']])) $people[(int)$row['id']]=$row;
        $templates=array_filter($this->templates(true),fn($row)=>isset($people[(int)$row['member_id']]));
        $person=self::matchObservations($payload['observations'],$templates,$this->policy['ATTENDANCE_THRESHOLD'],$diagnostics);
        $extra=[];
        if(!empty($this->policy['isolated_pilot'])){
            $camera=[];$reported=$payload['capture_diagnostics'] ?? [];
            foreach(['camera_start_ms','camera_width','camera_height','frame_count','quality_rejections','detection_ms','inference_ms','brightness','blur','clipped_fraction'] as $field){$value=is_array($reported)?($reported[$field] ?? null):null;if((is_int($value)||is_float($value)) && is_finite((float)$value) && $value>=0 && $value<=1e8)$camera[$field]=$value;}
            $extra=['diagnostics'=>$diagnostics+['request_key'=>$key,'batch_id'=>(int)$scan['batch_id'],'session_id'=>(int)$scan['session_id'],'attendance_date'=>$scan['attendance_date'],'matching_ms'=>round((microtime(true)-$matchingStarted)*1000,2),'camera_reported'=>$camera]];
        }
        if($person===null) return ['state'=>'UNKNOWN','message'=>'Face not recognized; no attendance marked']+$extra;
        $p=$people[$person];
        try{$result=(new AttendanceDomainService($this->db))->recordMemberAttendance([
            'branch_id'=>$this->branch,'batch_id'=>(int)$scan['batch_id'],'attendance_date'=>$scan['attendance_date'],'session_id'=>(int)$scan['session_id'],
            'member_id'=>(int)$p['contact_id'],'person_type'=>'M','negative'=>0,'source'=>'FACE_MATCH','event_type'=>'PUNCH_IN','actor_employee_id'=>$this->actor,
            'client_attempt_id'=>'face_'.hash('sha256',$key),'scan_id'=>(int)$scan['id'],'check_month'=>true,
            'metadata'=>['biometric_acceptance'=>true,'matching_report_sha256'=>$this->policy['report_sha256'] ?? null,'isolated_pilot'=>!empty($this->policy['isolated_pilot'])]]);
        }catch(AttendanceDomainException $e){return ['state'=>'REJECTED','reason'=>$e->reason]+$extra;}
        if(isset($result['current']['negative']) && (int)$result['current']['negative']!==0)return ['state'=>'REJECTED','reason'=>'EXISTING_MANUAL_STATUS']+$extra;
        return ['state'=>$result['state'],'person_id'=>$person,'contact_id'=>(int)$p['contact_id'],'person_name'=>$p['name'],'attendance_id'=>(int)$result['attendance_id'],'deducted_sessions'=>(int)($result['deducted_sessions'] ?? 0),'message'=>'Attendance marked']+$extra;
    }

    private function testStore(): BrowserFaceTestAttendance
    {
        if($this->mode()!=='TEST' || empty($this->policy['testing_evaluation']) || $this->branch!==2 || !empty($this->policy['isolated_pilot']))throw new InvalidArgumentException('Testing attendance is unavailable');
        return $this->testStoreFactory ? ($this->testStoreFactory)($this->db) : BrowserFaceTestAttendance::connect($this->db);
    }
    private function testContext(array $payload): array
    {
        $roster=$this->roster($payload);$session=$payload['attendance_session_id'] ?? null;
        if(!is_int($session) || !array_filter($roster['sessions'],fn($s)=>(int)$s['id']===$session && $s['start_time']===($payload['selected_time'] ?? '')))throw new InvalidArgumentException('Select the scheduled Testing session');
        return ['batch_id'=>$payload['batch_id'],'session_id'=>$session,'attendance_date'=>$payload['attendance_date']];
    }
    public function testAttendanceStatus(array $payload): array
    {
        $context=$this->testContext($payload);$store=$this->testStore();
        return ['state'=>'TEST_ATTENDANCE_STATUS','test_only'=>true,'persons'=>$store->status($context,$this->eligible($context['batch_id'],$context['attendance_date']))];
    }
    private function testPunch(array $payload,string $key): array
    {
        $context=$this->testContext($payload);$store=$this->testStore();$candidates=$payload['candidates'] ?? null;
        if(!is_array($candidates) || !$candidates || count($candidates)>100 || array_keys($candidates)!==range(0,count($candidates)-1)){
            $this->audit('attendance_test_punch_rejected_empty',['reason'=>'EMPTY_OR_INVALID_LIST','persistence_succeeded'=>false]);throw new InvalidArgumentException('Scan a member before punching test attendance');
        }
        $people=array_column($this->eligible($context['batch_id'],$context['attendance_date']),null,'id');
        $persisted=array_column($store->status($context,array_values($people)),null,'person_id');$selected=[];
        $refs=array_column($this->testTemplates(),null,'member_id');$conflicts=$this->testGalleryConflicts();
        // Authorize ALL receipts before any write. Predictions cannot grant access.
        foreach($candidates as $c){
            $id=$c['person_id'] ?? null;$receipt=$c['receipt_key'] ?? '';
            if(!is_int($id) || isset($selected[$id]) || !isset($people[$id]) || !is_string($receipt))throw new InvalidArgumentException('Invalid Testing member');
            $saved=$this->one("SELECT response FROM face_web_request WHERE actor_id=? AND request_key=? AND action='attendance'",[$this->actor,$receipt]);$r=$saved?json_decode($saved['response'],true):[];
            $scan=$this->one("SELECT *,expires_at>NOW() AS unexpired FROM attendance_scan WHERE id=? AND actor_employee_id=? AND branch_id=? AND provider_id='browser-onnx' AND model_version_id=?",[$r['test_scan_id'] ?? 0,$this->actor,$this->branch,$this->modelId]);
            if(($r['state'] ?? '')!=='TEST_RECOGNIZED' || ($r['person_id'] ?? null)!==$id || !$scan
                || (int)$scan['batch_id']!==$context['batch_id'] || (int)$scan['session_id']!==$context['session_id'] || $scan['attendance_date']!==$context['attendance_date']
                || $scan['calibration_policy_version']!==$this->scanBinding())throw new InvalidArgumentException('Recognition belongs to another session');
            if(!isset($persisted[$id]) && (!(int)$scan['unexpired'] || !in_array($scan['status'],['ACTIVE','COMPLETED'],true) || !isset($refs[$id]) || isset($conflicts[$id])
                || (int)$r['matched_reference_capture_id']!==(int)($refs[$id]['test_capture_id'] ?? $refs[$id]['calibration_id'])))throw new InvalidArgumentException('Face or session changed. Please rescan');
            $selected[$id]=$people[$id]+['receipt_key'=>$receipt];
        }
        if($this->sessionCancelled($context))throw new InvalidArgumentException('Attendance session is unavailable');
        $results=$store->record($context,array_values($selected),$this->actor,$key);
        $ok=!array_filter($results,fn($r)=>!in_array($r['state'],['RECORDED','ALREADY_MARKED'],true));
        return ['state'=>$ok?'TEST_PUNCHED':'PARTIAL_TEST_PUNCH','results'=>$results,'test_only'=>true,'attendance_saved'=>false,'test_attendance_saved'=>$ok,'message'=>$ok?'Test attendance punched.':'Some test attendance could not be saved. Please retry.'];
    }

    private function cutoff(string $date,array $session): ?DateTimeImmutable {
        if(empty($session['end_time']) || $session['end_time']==='00:00:00')return null;
        $end=new DateTimeImmutable($date.' '.$session['end_time'],new DateTimeZone('Asia/Kolkata'));
        return $session['end_time']<=$session['start_time']?$end->modify('+1 day'):$end;
    }
    private function sessionCancelled(array $scan): bool {
        return (bool)$this->one("SELECT id FROM attendance WHERE batch_id=? AND date=? AND (session_id=? OR session_id=0) AND type='B' AND park=0 LIMIT 1",[$scan['batch_id'],$scan['attendance_date'],$scan['session_id']]);
    }

    private function finalize(array $payload,string $key): array {
        $scan=$this->scan($payload);
        if(!$this->activationAllowed() || empty($this->policy['rollout_authorized']) || empty($this->policy['finalize_absences']) || !empty($this->policy['isolated_pilot']) || $scan['calibration_policy_version']!==$this->scanBinding())throw new InvalidArgumentException('Authorized LIVE session finalization required');
        if($this->sessionCancelled($scan))throw new InvalidArgumentException('Attendance session is unavailable');
        $roster=$this->roster(['batch_id'=>(int)$scan['batch_id'],'attendance_date'=>$scan['attendance_date']]);
        $scheduled=array_values(array_filter($roster['sessions'],fn($r)=>(int)$r['id']===(int)$scan['session_id']));
        if(!$scheduled || !($end=$this->cutoff($scan['attendance_date'],$scheduled[0])) || new DateTimeImmutable('now',$end->getTimezone())<$end)throw new InvalidArgumentException('Attendance cutoff has not been reached');
        $settings=$this->one('SELECT deduct_session_absentee FROM attendance_settings WHERE bid=?',[$this->branch]);
        if(!$settings || !in_array((int)$settings['deduct_session_absentee'],[0,1],true))throw new InvalidArgumentException('Branch absence policy required');
        $results=[];
        foreach($roster['persons'] as $p){
            $existing=$this->one("SELECT id,negative FROM attendance WHERE contact_id=? AND batch_id=? AND date=? AND session_id=? AND type='M' AND park=0",[$p['contact_id'],$scan['batch_id'],$scan['attendance_date'],$scan['session_id']]);
            if($existing){$results[]=['person_id'=>(int)$p['id'],'contact_id'=>(int)$p['contact_id'],'state'=>'PRESERVED','negative'=>(int)$existing['negative'],'attendance_id'=>(int)$existing['id']];continue;}
            try{$r=(new AttendanceDomainService($this->db))->recordMemberAttendance([
                'branch_id'=>$this->branch,'batch_id'=>(int)$scan['batch_id'],'attendance_date'=>$scan['attendance_date'],'session_id'=>(int)$scan['session_id'],'member_id'=>(int)$p['contact_id'],'person_type'=>'M','negative'=>1,
                'source'=>'MANUAL','event_type'=>'ATTENDANCE_FINALIZED','actor_employee_id'=>$this->actor,'client_attempt_id'=>'finalize_'.hash('sha256',implode(':',[$this->branch,$scan['batch_id'],$scan['attendance_date'],$scan['session_id'],$p['id']])),
                'check_month'=>true,'deduct_session_absentee'=>(int)$settings['deduct_session_absentee'],'attendance_session'=>(int)$settings['deduct_session_absentee'],
                'metadata'=>['scheduled_cutoff'=>$end->format(DATE_ATOM),'rollout_scope_sha256'=>$this->policy['rollout_scope_sha256'] ?? null,'automatic_session_finalization'=>true]]);
                $results[]=['person_id'=>(int)$p['id'],'contact_id'=>(int)$p['contact_id'],'state'=>$r['state'],'reason'=>$r['reason'] ?? '', 'attendance_id'=>(int)($r['attendance_id'] ?? 0),'negative'=>(int)($r['current']['negative'] ?? 1),'deducted_sessions'=>(int)($r['deducted_sessions'] ?? 0)];
            }catch(AttendanceDomainException $e){$results[]=['person_id'=>(int)$p['id'],'contact_id'=>(int)$p['contact_id'],'state'=>'REJECTED','reason'=>$e->reason];}
        }
        $partial=(bool)array_filter($results,fn($r)=>$r['state']==='REJECTED');
        if(!$partial)$this->execute("UPDATE attendance_scan SET status='FINALIZED',completed_at=NOW() WHERE id=?",[$scan['id']])->close();
        return ['state'=>$partial?'PARTIAL_FINALIZATION':'FINALIZED','results'=>$results,'message'=>$partial?'Some attendance records need attention.':'Session attendance finalized.'];
    }

    private function suggestion(array $person,bool $validated): array
    {
        return ['branch_id'=>$this->branch,'person_id'=>(int)$person['id'],'contact_id'=>(int)$person['contact_id'],'person_name'=>$person['name'],
            'recognition_result'=>$validated?'RECOGNIZED':'UNVERIFIED_SUGGESTION','requires_independent_verification'=>!$validated];
    }

    private function confirm(array $payload,string $key): array
    {
        $scan=$this->scan($payload);
        if($this->mode()==='TEST' || str_starts_with($scan['calibration_policy_version'],'browser-face-TEST:'))throw new InvalidArgumentException('TEST recognition cannot confirm operational attendance');
        if(($payload['verified'] ?? null)!==true)throw new InvalidArgumentException('Operator verification required');
        $candidates=$payload['candidates'] ?? null;
        if(!is_array($candidates) || !$candidates || count($candidates)>100 || array_keys($candidates)!==range(0,count($candidates)-1))throw new InvalidArgumentException('Reviewed member list required');
        $roster=$this->roster(['batch_id'=>(int)$scan['batch_id'],'attendance_date'=>$scan['attendance_date']]);
        if(!array_filter($roster['sessions'],fn($r)=>(int)$r['id']===(int)$scan['session_id']))throw new InvalidArgumentException('Attendance session is no longer scheduled');
        $people=[];foreach($roster['persons'] as $p)$people[(int)$p['id']]=$p;
        $selected=[];
        // Validate the entire authorization/receipt set before the first write.
        foreach($candidates as $candidate){
            if(!is_array($candidate))throw new InvalidArgumentException('Invalid reviewed member');
            $id=$candidate['person_id'] ?? null;$receipt=$candidate['receipt_key'] ?? '';
            if(!is_int($id) || isset($selected[$id]) || !is_string($receipt))throw new InvalidArgumentException('Invalid reviewed member');
            $person=$this->person($id);
            if(!$this->one("SELECT id FROM attendance_scan_roster WHERE scan_id=? AND member_id=? AND eligibility_status='ELIGIBLE'",[$scan['id'],$id]))throw new InvalidArgumentException('Member is not in this scan roster');
            $saved=$this->one("SELECT response FROM face_web_request WHERE actor_id=? AND request_key=? AND action='attendance'",[$this->actor,$receipt]);
            $response=$saved?json_decode($saved['response'],true):[];
            // The request hash cannot reconstruct context; bind each staged
            // receipt explicitly to its server-owned scan/context in the journal.
            if((int)($response['review_scan_id'] ?? 0)!==(int)$scan['id'] || (int)($response['suggestion']['person_id'] ?? 0)!==$id
                || !in_array($response['state'] ?? '',['REVIEW_CANDIDATE','SHADOW_EVALUATED'],true))throw new InvalidArgumentException('Recognition suggestion is not authorized for this session');
            $selected[$id]=$person;
        }
        $results=[];
        foreach($selected as $id=>$person){
            $attempt='review_'.hash('sha256',$key.':'.$id);
            $existing=$this->one("SELECT id,negative FROM attendance WHERE contact_id=? AND batch_id=? AND date=? AND session_id=? AND type='M' AND park=0 ORDER BY id DESC LIMIT 1",[$person['contact_id'],$scan['batch_id'],$scan['attendance_date'],$scan['session_id']]);
            if($existing){$result=(int)$existing['negative']===0?['state'=>'ALREADY_MARKED','attendance_id'=>(int)$existing['id'],'deducted_sessions'=>0]:['state'=>'REJECTED','reason'=>'EXISTING_MANUAL_STATUS'];}
            elseif($scan['status']!=='ACTIVE' || !(int)$scan['unexpired'])$result=['state'=>'REJECTED','reason'=>'SESSION_EXPIRED'];
            elseif(!isset($people[$id]))$result=['state'=>'REJECTED','reason'=>'INELIGIBLE'];
            elseif($this->mode()!=='SHADOW')$result=['state'=>'REJECTED','reason'=>'SCAN_MODE_CHANGED'];
            else{
                try{
                    $result=(new AttendanceDomainService($this->db))->recordMemberAttendance([
                        'branch_id'=>$this->branch,'batch_id'=>(int)$scan['batch_id'],'attendance_date'=>$scan['attendance_date'],
                        'session_id'=>(int)$scan['session_id'],'member_id'=>(int)$person['contact_id'],'person_type'=>'M','negative'=>0,
                        'source'=>'TEACHER_CONFIRMED','event_type'=>'PUNCH_IN','actor_employee_id'=>$this->actor,'client_attempt_id'=>$attempt,'scan_id'=>(int)$scan['id'],'check_month'=>true,
                        'metadata'=>['operator_confirmed'=>true,'biometric_acceptance'=>false,'review_request'=>$key]]);
                    if(isset($result['current']['negative']) && (int)$result['current']['negative']!==0)$result=['state'=>'REJECTED','reason'=>'EXISTING_MANUAL_STATUS'];
                }catch(AttendanceDomainException $e){$result=['state'=>'REJECTED','reason'=>$e->reason];}
                // Infrastructure failure must propagate: the reserved key and
                // per-person domain event recover already committed members.
            }
            $results[]=['person_id'=>$id,'contact_id'=>(int)$person['contact_id'],'person_name'=>$person['name'],'state'=>$result['state'],
                'attendance_id'=>(int)($result['attendance_id'] ?? 0),'deducted_sessions'=>(int)($result['deducted_sessions'] ?? 0),'reason'=>$result['reason'] ?? ''];
        }
        $ok=!array_filter($results,fn($r)=>!in_array($r['state'],['RECORDED','ALREADY_MARKED'],true));
        return ['state'=>$ok?'CONFIRMED':'PARTIAL_CONFIRMATION','review_scan_id'=>(int)$scan['id'],'results'=>$results,'message'=>$ok?'Attendance confirmed.':'Some members could not be saved. Review and retry.'];
    }

    public function migrationStatus(): array
    {
        $rows=$this->rows("SELECT ft.id AS template_id,ft.member_id,ft.model_version_id,m.contact_id,m.bid AS branch_id,CONCAT_WS(' ',c.fname,c.lname) AS name,mv.model_name,mv.preprocessing_version FROM face_template ft JOIN member m ON m.id=ft.member_id JOIN contact c ON c.id=m.contact_id JOIN face_model_version mv ON mv.id=ft.model_version_id WHERE ft.status='ACTIVE' AND ft.model_version_id<>? AND m.bid=? ORDER BY m.id,ft.id",[$this->modelId,$this->branch]);
        foreach($rows as &$row) $row['migration_authorized']=$this->migrationApproval((int)$row['member_id'])!==null;
        unset($row);
        return ['legacy_templates'=>$rows,'incompatible_active_count'=>(int)$this->one("SELECT COUNT(*) AS count FROM face_template WHERE status='ACTIVE' AND model_version_id<>?",[$this->modelId])['count']];
    }

    private function migrationApproval(int $member): ?array
    {
        $path=FaceAttendanceConfig::getEnv('FACE_WEB_LEGACY_MIGRATION_FILE');
        $policy=$path!=='' && is_readable($path) ? json_decode(file_get_contents($path),true) : null;
        if(!is_array($policy) || ($policy['approved'] ?? false)!==true || empty($policy['approved_by'])
            || ($policy['database'] ?? '')!==$this->one('SELECT DATABASE() AS name')['name']
            || strtotime($policy['expires_at'] ?? '')<=time()) return null;
        foreach($policy['members'] ?? [] as $entry) if((int)($entry['member_id'] ?? 0)===$member && (int)($entry['branch_id'] ?? 0)===$this->branch) return $entry+['approved_by'=>$policy['approved_by']];
        return null;
    }

    private function migrate(array $payload,string $key): array
    {
        $this->versions($payload);$vector=self::vector($payload['template'] ?? null);$person=$this->person($payload['person_id'] ?? null);
        if(!$this->activationAllowed()) return ['state'=>'CALIBRATION_REQUIRED','message'=>BrowserFacePolicy::SETUP_REQUIRED_MESSAGE];
        $approval=$this->migrationApproval((int)$person['id']);
        if(!$approval) throw new InvalidArgumentException('Explicit legacy migration authorization required');
        if($this->one("SELECT id FROM face_template WHERE status='ACTIVE' AND model_version_id<>? AND member_id<>? LIMIT 1",[$this->modelId,$person['id']])) return ['state'=>'MIGRATION_SCOPE_BLOCKED','message'=>'Other incompatible identities remain; a coordinated migration requires review'];
        $legacy=$this->rows("SELECT id,model_version_id,status,encrypted_embedding FROM face_template WHERE member_id=? AND status='ACTIVE' AND model_version_id<>? ORDER BY id",[$person['id'],$this->modelId]);
        $ids=array_map(fn($r)=>(int)$r['id'],$legacy);$requested=$payload['legacy_template_ids'] ?? null;
        if(!$ids || !is_array($requested) || $requested!==$ids || ($approval['legacy_template_ids'] ?? null)!==$ids) throw new InvalidArgumentException('Legacy template set changed; authorization must be reviewed');
        $fingerprint=hash('sha256',implode('|',array_map(fn($r)=>$r['id'].':'.$r['model_version_id'].':'.hash('sha256',$r['encrypted_embedding']),$legacy)));
        if(!is_string($approval['fingerprint'] ?? null) || !hash_equals($approval['fingerprint'],$fingerprint)) throw new InvalidArgumentException('Legacy template fingerprint changed');
        if($this->one("SELECT id FROM face_template WHERE member_id=? AND status='ACTIVE' AND model_version_id=?",[$person['id'],$this->modelId])) throw new InvalidArgumentException('Compatible enrollment already exists; replacement requires review');
        foreach($this->templates() as $other) if(self::cosine($vector,$other['vector']) >= $this->policy['DUPLICATE_THRESHOLD']) return ['state'=>'DUPLICATE','message'=>'Duplicate detected; legacy enrollment unchanged'];
        $this->execute("INSERT INTO biometric_consent(member_id,guardian_contact_id,notice_version,purpose,consent_status,verification_method,actor_employee_id,consented_at) VALUES(?,NULL,'browser-face-v1','ATTENDANCE','PROGRAM_AUTHORIZED','OPERATOR_INITIATED',?,NOW())",[$person['id'],$this->actor]);
        $summary=json_encode(['source'=>'BROWSER_LEGACY_REPLACEMENT','preprocessing_version'=>$this->contract['preprocessing_version'],'created_by_actor_id'=>$this->actor]);
        $this->execute("INSERT INTO face_template(member_id,model_version_id,encrypted_embedding,encryption_key_id,embedding_dimension,quality_summary,status) VALUES(?,?,?,?,128,?,'ACTIVE')",[$person['id'],$this->modelId,$this->cipher->encrypt($vector),$this->cipher->keyId(),$summary]);
        $replacement=(int)$this->db->insert_id;$snapshot=[];
        foreach($legacy as $row){$snapshot[]=['template_id'=>(int)$row['id'],'model_version_id'=>(int)$row['model_version_id'],'previous_status'=>$row['status']];$this->execute("UPDATE face_template SET status='SUPERSEDED',revoked_at=NOW() WHERE id=? AND member_id=? AND status='ACTIVE'",[$row['id'],$person['id']]);}
        $this->execute('INSERT INTO face_web_migration(branch_id,member_id,actor_id,replacement_template_id,legacy_snapshot,request_key,approved_by) VALUES(?,?,?,?,?,?,?)',[$this->branch,$person['id'],$this->actor,$replacement,json_encode($snapshot),$key,$approval['approved_by']]);
        return ['state'=>'ENROLLED','person_id'=>(int)$person['id'],'contact_id'=>(int)$person['contact_id'],'message'=>'Enrollment saved; legacy template replaced','replacement_template_id'=>$replacement];
    }

    private function collectCalibration(array $payload): array
    {
        $this->versions($payload);$vector=self::vector($payload['template'] ?? null);$person=$this->person($payload['person_id'] ?? null);
        if(($payload['consent'] ?? false)!==true) throw new InvalidArgumentException('Explicit informed calibration consent required');
        foreach(['visit','device','lighting'] as $field) if(!is_string($payload[$field] ?? null) || trim($payload[$field])==='' || strlen($payload[$field])>100) throw new InvalidArgumentException('Calibration visit, device and lighting labels required (maximum 100 bytes)');
        if(!in_array($payload['split'] ?? '',['calibration','validation'],true)) throw new InvalidArgumentException('Separate calibration or validation split required');
        $this->execute("INSERT INTO face_web_calibration(branch_id,member_id,actor_id,encrypted_embedding,encryption_key_id,model_version,preprocessing_version,visit,device,lighting,dataset_split,consent_notice,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'browser-calibration-v1',DATE_ADD(NOW(),INTERVAL 30 DAY))",[$this->branch,$person['id'],$this->actor,$this->cipher->encrypt($vector),$this->cipher->keyId(),$this->contract['model_version'],$this->contract['preprocessing_version'],trim($payload['visit']),trim($payload['device']),trim($payload['lighting']),$payload['split']]);
        return ['state'=>'CALIBRATION_SAVED','sample_id'=>(int)$this->db->insert_id,'message'=>'Calibration observation saved securely; no enrollment or attendance created'];
    }

    public function calibrationProgress(): array
    {
        $rows=$this->rows('SELECT id,member_id,visit,device,lighting,dataset_split,consented_at,expires_at FROM face_web_calibration WHERE branch_id=? AND deleted_at IS NULL AND expires_at>NOW() AND model_version=? AND preprocessing_version=? ORDER BY id DESC',[$this->branch,$this->contract['model_version'],$this->contract['preprocessing_version']]);
        return ['samples'=>count($rows),'identities'=>count(array_unique(array_column($rows,'member_id'))),'validation_samples'=>count(array_filter($rows,fn($r)=>$r['dataset_split']==='validation')),'observations'=>$rows,'retention_days'=>30];
    }

    private function deleteCalibration(array $payload): array
    {
        $person=$this->person($payload['person_id'] ?? null);
        $this->execute("UPDATE face_web_calibration SET encrypted_embedding='',deleted_at=NOW() WHERE branch_id=? AND member_id=? AND deleted_at IS NULL",[$this->branch,$person['id']]);
        if($this->lifecycle){$this->eraseEvidence((int)$person['id']);$this->execute("UPDATE face_web_enrollment SET state='RECAPTURE_REQUIRED',encrypted_template='',decision_reason='CONSENT_WITHDRAWN' WHERE member_id=? AND branch_id=? AND state<>'ACTIVE'",[$person['id'],$this->branch]);}
        return ['state'=>'CALIBRATION_DELETED','message'=>'Private calibration observations erased; enrollment and attendance unchanged'];
    }

    private function execute(string $sql, array $values = [])
    {
        $stmt = $this->db->prepare($sql);
        if ($values) { $types = str_repeat('s',count($values)); $stmt->bind_param($types,...$values); }
        $stmt->execute();
        return $stmt;
    }
    private function rows(string $sql, array $values = []): array
    {
        $stmt=$this->execute($sql,$values);
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $rows;
    }
    private function one(string $sql, array $values = []): ?array {return $this->rows($sql,$values)[0] ?? null;}
}
