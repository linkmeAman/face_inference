<?php
// CLI-only private biometric operations. Never loaded by a web route.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/classes/dbconnection.class.php';
require_once dirname(__DIR__).'/classes/services/BrowserFaceService.php';
function privateFile(string $path,string $bytes): void {
    $parent=realpath(dirname($path));$root=realpath(dirname(__DIR__));
    if(!$parent || $parent===$root || strpos($parent,$root.DIRECTORY_SEPARATOR)===0)throw new InvalidArgumentException('Output must be outside the public repository');
    $old=umask(0077);$f=fopen($path,'x');umask($old);if(!$f)throw new RuntimeException('Private output already exists or cannot be created');
    try{if(fwrite($f,$bytes)!==strlen($bytes))throw new RuntimeException('Incomplete private write');}finally{fclose($f);}
    chmod($path,0600);
}
function adminMain(array $argv, ?mysqli $testConnection=null): void {
    $command=$argv[1] ?? ''; $args=[];
    for($i=2;$i<count($argv);$i++){if(substr($argv[$i],0,2)!=='--')throw new InvalidArgumentException('Named arguments required');$k=substr($argv[$i],2);$args[$k]=isset($argv[$i+1]) && substr($argv[$i+1],0,2)!=='--'?$argv[++$i]:true;}
    $database=$args['database'] ?? '';if(!is_string($database) || !preg_match('/^[a-zA-Z0-9_]+$/D',$database))throw new InvalidArgumentException('--database required');
    $branch=(int)($args['branch'] ?? 0);if($branch<1)throw new InvalidArgumentException('--branch required');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=$testConnection ?? (new dbconnection())->connect($database);if($testConnection && $database!=='ccc_database_test')throw new RuntimeException('Temporary regression connection only');$db->set_charset('utf8mb4');
    if($command==='field-status'){
        $s=$db->prepare("SELECT state,COUNT(*) AS count FROM face_web_enrollment WHERE branch_id=? GROUP BY state");$s->bind_param('i',$branch);$s->execute();$states=$s->get_result()->fetch_all(MYSQLI_ASSOC);
        $batch=(int)($args['batch'] ?? 0);$conditions="created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)";
        // Scope request metrics through owned scan IDs/staged response context;
        // enrollment requests use their selected member's actual branch.
        $scope="(CAST(JSON_UNQUOTE(JSON_EXTRACT(response,'$.shadow_context.branch_id')) AS UNSIGNED)=? OR CAST(JSON_UNQUOTE(JSON_EXTRACT(response,'$.review_scan_id')) AS UNSIGNED) IN (SELECT id FROM attendance_scan WHERE branch_id=?) OR CAST(JSON_UNQUOTE(JSON_EXTRACT(response,'$.person_id')) AS UNSIGNED) IN (SELECT id FROM member WHERE bid=?))";
        $s=$db->prepare("SELECT action,JSON_UNQUOTE(JSON_EXTRACT(response,'$.state')) AS state,COUNT(*) AS count FROM face_web_request WHERE $conditions AND $scope GROUP BY action,state");$s->bind_param('iii',$branch,$branch,$branch);$s->execute();$requests=$s->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['database'=>$database,'branch_id'=>$branch,'pending_states'=>$states,'last_seven_days'=>$requests,'matching_ready'=>BrowserFacePolicy::load()['ready'],'rollout'=>BrowserFacePolicy::rollout($database,$branch,$batch,BrowserFacePolicy::load())],JSON_PRETTY_PRINT),PHP_EOL;return;
    }
    if($command==='disable-field'){
        if(empty($args['apply']) || trim((string)($args['authorized-by'] ?? ''))==='')throw new InvalidArgumentException('Explicit --apply and --authorized-by required');
        $file=FaceAttendanceConfig::getEnv('FACE_WEB_ROLLOUT_FILE');$path=realpath($file);$root=realpath(dirname(__DIR__));
        if(!$path || strpos($path,$root.DIRECTORY_SEPARATOR)===0 || (fileperms($path)&0007)!==0)throw new InvalidArgumentException('Protected rollout file required');
        $config=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);if(($config['version'] ?? '')!=='browser-face-rollout-v1')throw new InvalidArgumentException('Invalid rollout file');
        $batch=(int)($args['batch'] ?? 0);
        if($batch){array_unshift($config['scopes'],['database'=>$database,'branch_id'=>$branch,'batch_id'=>$batch,'mode'=>'DISABLED','approved_by'=>(string)$args['authorized-by'],'approved_at'=>gmdate('c')]);}
        else $config['enabled']=false;
        $config['last_change']=['at'=>gmdate('c'),'by'=>(string)$args['authorized-by'],'reason'=>'FIELD_EMERGENCY_STOP'];
        $next=$path.'.'.bin2hex(random_bytes(8)).'.next';privateFile($next,json_encode($config,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
        chmod($next,fileperms($path)&0777);chgrp($next,filegroup($path));if(!rename($next,$path)){unlink($next);throw new RuntimeException('Atomic rollout update failed');}
        echo $batch?'Field processing disabled for exact tenant/branch/batch.':'Field processing disabled globally; manual attendance and committed history preserved.';echo PHP_EOL;return;
    }
    if(in_array($command,['export-shadow','purge-shadow'],true)){
        $batch=(int)($args['batch'] ?? 0);if($batch<1)throw new InvalidArgumentException('Exact --batch required');
        $scope="action='attendance' AND JSON_UNQUOTE(JSON_EXTRACT(response,'$.state'))='SHADOW_EVALUATED' AND JSON_EXTRACT(response,'$.shadow_context.branch_id')=? AND JSON_EXTRACT(response,'$.shadow_context.batch_id')=?";
        if($command==='export-shadow'){
            $s=$db->prepare("SELECT actor_id,request_key,created_at,response FROM face_web_request WHERE $scope AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY created_at,request_key");$s->bind_param('ii',$branch,$batch);$s->execute();$bytes='';$n=0;
            foreach($s->get_result() as $row){$row['response']=json_decode($row['response'],true,32,JSON_THROW_ON_ERROR);$bytes.=json_encode($row,JSON_THROW_ON_ERROR)."\n";$n++;}
            privateFile((string)($args['output'] ?? ''),$bytes);echo "Exported $n private unlabelled shadow summaries; predictions are not identity labels.\n";return;
        }
        if(empty($args['apply']) || trim((string)($args['authorized-by'] ?? ''))==='')throw new InvalidArgumentException('Explicit --apply and --authorized-by required');
        $s=$db->prepare("UPDATE face_web_request SET response=JSON_SET(JSON_REMOVE(response,'$.diagnostics'),'$.shadow_context.evidence_expired',TRUE) WHERE $scope AND created_at<DATE_SUB(NOW(),INTERVAL 30 DAY) AND JSON_EXTRACT(response,'$.diagnostics') IS NOT NULL");$s->bind_param('ii',$branch,$batch);$s->execute();echo 'Expired shadow scores redacted: '.$s->affected_rows.'. Request recovery and attendance history retained.'.PHP_EOL;return;
    }
    if(in_array($command,['prepare-label-exclusion','apply-label-exclusion','rollback-label-exclusion'],true)){
        require_once dirname(__DIR__).'/classes/services/BrowserFaceEvidenceExclusion.php';
        if($command==='prepare-label-exclusion'){
            $list=(string)($args['sample-ids'] ?? '');if(!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D',$list))throw new InvalidArgumentException('Exact --sample-ids required');
            $plan=BrowserFaceEvidenceExclusion::prepare($db,$branch,(int)($args['retain-member'] ?? 0),array_map('intval',explode(',',$list)),(string)($args['reason'] ?? ''));
            privateFile((string)($args['output'] ?? ''),json_encode($plan,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            echo 'Prepared reversible exclusion; no records changed. Members: '.implode(',',$plan['affected_members'])."\n";return;
        }
        if(empty($args['apply']) || trim((string)($args['authorized-by'] ?? ''))==='')throw new InvalidArgumentException('Explicit --apply and --authorized-by required');
        $path=(string)($args['audit'] ?? '');$root=realpath(dirname(__DIR__));$resolved=realpath($path);
        if(!$resolved || strpos($resolved,$root.DIRECTORY_SEPARATOR)===0 || (fileperms($resolved)&0077)!==0)throw new InvalidArgumentException('Private protected audit required');
        $plan=json_decode(file_get_contents($resolved),true,512,JSON_THROW_ON_ERROR);if(($plan['branch_id'] ?? 0)!==$branch)throw new InvalidArgumentException('Branch mismatch');
        $result=BrowserFaceEvidenceExclusion::change($db,$plan,$command==='rollback-label-exclusion');
        // Append-only protected administrative evidence; restart-safe DB snapshot handles replay.
        $event=['time'=>gmdate('c'),'action'=>$command,'authorized_by'=>(string)$args['authorized-by'],'snapshot_sha256'=>$plan['snapshot_sha256'],'result'=>$result];
        $old=umask(0077);file_put_contents($resolved.'.events.jsonl',json_encode($event,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);umask($old);chmod($resolved.'.events.jsonl',0600);echo $result."\n";return;
    }
    if($command==='inspect-migration'){
        $contract=BrowserFacePolicy::contract();$s=$db->prepare("SELECT ft.id,ft.member_id,ft.model_version_id,ft.encrypted_embedding,m.contact_id FROM face_template ft JOIN member m ON m.id=ft.member_id JOIN face_model_version mv ON mv.id=ft.model_version_id WHERE ft.status='ACTIVE' AND mv.preprocessing_version<>? AND m.bid=? ORDER BY ft.member_id,ft.id");$s->bind_param('si',$contract['preprocessing_version'],$branch);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$members=[];
        foreach($rows as $r)$members[(int)$r['member_id']][]=$r;$entries=[];
        foreach($members as $id=>$templates)$entries[]=['member_id'=>$id,'branch_id'=>$branch,'contact_id'=>(int)$templates[0]['contact_id'],'legacy_template_ids'=>array_map(fn($r)=>(int)$r['id'],$templates),'fingerprint'=>hash('sha256',implode('|',array_map(fn($r)=>$r['id'].':'.$r['model_version_id'].':'.hash('sha256',$r['encrypted_embedding']),$templates)))];
        $policy=['database'=>$database,'approved'=>false,'approved_by'=>'','expires_at'=>'','members'=>$entries];
        echo json_encode($policy,JSON_PRETTY_PRINT)."\n";if(isset($args['output']))privateFile($args['output'],json_encode($policy,JSON_PRETTY_PRINT)."\n");return;
    }
    if($command==='export-calibration'){
        $cipher=new FaceEmbeddingCipher(FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY'),FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY_ID'));$contract=BrowserFacePolicy::contract();
        $s=$db->prepare('SELECT * FROM face_web_calibration WHERE branch_id=? AND deleted_at IS NULL AND expires_at>NOW() AND model_version=? AND preprocessing_version=? ORDER BY id');$s->bind_param('iss',$branch,$contract['model_version'],$contract['preprocessing_version']);$s->execute();$rows=$s->get_result();$bytes='';$count=0;
        $extended=(bool)$db->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='face_web_evidence'")->num_rows;
        while($r=$rows->fetch_assoc()){if($r['encryption_key_id']!==$cipher->keyId())throw new RuntimeException('Calibration key mismatch');$vector=BrowserFaceService::vector($cipher->decrypt($r['encrypted_embedding']));
            $record=['origin_database'=>$database,'branch_id'=>$branch,'identity'=>(string)$r['member_id'],'embedding'=>$vector,'visit'=>$r['visit'],'device'=>$r['device'],'lighting'=>$r['lighting'],'split'=>$r['dataset_split'],'consent'=>true,'source'=>'consented-live-cameras','model_version'=>$r['model_version'],'preprocessing_version'=>$r['preprocessing_version']];
            if($extended){$candidate=$db->prepare("SELECT capture_session FROM face_web_enrollment WHERE calibration_id=? AND state IN('PENDING','ACTIVE')");$candidate->bind_param('i',$r['id']);$candidate->execute();$candidateRow=$candidate->get_result()->fetch_assoc();if($candidateRow)$record+=['enrollment_candidate'=>true,'capture_session'=>$candidateRow['capture_session'],'purpose'=>'ENROLL'];
                $e=$db->prepare('SELECT e.*,(SELECT COUNT(*) FROM face_web_enrollment candidate WHERE candidate.calibration_id=e.calibration_id AND candidate.state IN(\'PENDING\',\'ACTIVE\')) AS enrollment_candidate FROM face_web_evidence e WHERE e.calibration_id=? AND LENGTH(e.encrypted_observations)>0');$e->bind_param('i',$r['id']);$e->execute();$full=$e->get_result()->fetch_assoc();
                if($full && $full['purpose']==='LABEL_CONFLICT')continue;
                if($full){if($full['encryption_key_id']!==$cipher->keyId())throw new RuntimeException('Observation key mismatch');$record+=['observations'=>json_decode($cipher->decryptBytes($full['encrypted_observations']),true,32,JSON_THROW_ON_ERROR),'capture_session'=>$full['capture_session'],'purpose'=>$full['purpose'],'evaluation_context'=>json_decode($full['evaluation_context'] ?? 'null',true),'enrollment_candidate'=>(bool)$full['enrollment_candidate']];}}
            $bytes.=json_encode($record,JSON_THROW_ON_ERROR)."\n";$count++;}
        if(!$count)throw new RuntimeException('No valid consented observations to export');privateFile($args['output'] ?? '',$bytes);echo "Exported $count private observations; remove the plaintext export after evaluation.\n";return;
    }
    if($command==='purge-expired'){
        if(empty($args['apply']))throw new InvalidArgumentException('--apply required to erase expired calibration ciphertext');
        $db->begin_transaction();try{
            if($db->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='face_web_evidence'")->num_rows){
                $s=$db->prepare("UPDATE face_web_evidence e JOIN face_web_calibration c ON c.id=e.calibration_id SET e.encrypted_observations='' WHERE c.branch_id=? AND (c.expires_at<=NOW() OR c.deleted_at IS NOT NULL)");$s->bind_param('i',$branch);$s->execute();
                $s=$db->prepare("UPDATE face_web_enrollment SET state='RECAPTURE_REQUIRED',encrypted_template='',decision_reason='EXPIRED' WHERE branch_id=? AND state<>'ACTIVE' AND expires_at<=NOW()");$s->bind_param('i',$branch);$s->execute();}
            $s=$db->prepare("UPDATE face_web_calibration SET encrypted_embedding='',deleted_at=NOW() WHERE branch_id=? AND deleted_at IS NULL AND expires_at<=NOW()");$s->bind_param('i',$branch);$s->execute();$n=$s->affected_rows;$db->commit();echo 'Expired observations erased: '.$n."\n";
        }catch(Throwable $e){$db->rollback();throw $e;}return;
    }
    if($command==='finalize-due'){
        // Reuse authorized server-owned scans and their original operator.
        // This can be scheduled by the existing cron system after LIVE approval.
        require_once dirname(__DIR__).'/classes/session.class.php';
        $base=BrowserFacePolicy::load();if(!$base['ready'])throw new RuntimeException('Validated approved release configuration required');
        $s=$db->prepare("SELECT id,scan_token,batch_id,actor_employee_id FROM attendance_scan WHERE branch_id=? AND provider_id='browser-onnx' AND calibration_policy_version LIKE 'browser-face-LIVE:%' AND attendance_date BETWEEN ? AND ? AND status IN('ACTIVE','COMPLETED') ORDER BY id ASC LIMIT 100");$from=date('Y-m-d',strtotime('-1 day'));$to=date('Y-m-d');$s->bind_param('iss',$branch,$from,$to);$s->execute();$scans=$s->get_result()->fetch_all(MYSQLI_ASSOC);$results=[];
        $cipher=new FaceEmbeddingCipher(FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY'),FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY_ID'));
        foreach($scans as $scan){
            $actor=(int)$scan['actor_employee_id'];$policy=$base+BrowserFacePolicy::rollout($database,$branch,(int)$scan['batch_id'],$base,null,$actor);
            if(($policy['operating_mode'] ?? '')!=='LIVE' || empty($policy['finalize_absences']) || !(new session)->access_app($actor,(new dbconnection)->module('Attendance'),1,$branch))continue;
            try{$r=(new BrowserFaceService($db,$cipher,$actor,$branch,$policy))->run('finalize',['scan_id'=>(int)$scan['id'],'scan_token'=>$scan['scan_token'],'idempotency_key'=>'scheduled_finalize_'.(int)$scan['id'].'_'.substr($policy['rollout_scope_sha256'],0,32)]);$results[]=['scan_id'=>(int)$scan['id'],'state'=>$r['state']];}
            catch(InvalidArgumentException $e){$results[]=['scan_id'=>(int)$scan['id'],'state'=>'NOT_DUE_OR_NOT_AUTHORIZED'];}
        }
        echo json_encode(['results'=>$results])."\n";return;
    }
    if($command==='activate-pending'){
        $actor=(int)($args['actor'] ?? 0);if($actor<1)throw new InvalidArgumentException('--actor required');
        require_once dirname(__DIR__).'/classes/session.class.php';$session=new session;
        if(!$session->access_app($actor,(new dbconnection)->module('Attendance'),1,$branch))throw new RuntimeException('Attendance permission required');
        $policy=BrowserFacePolicy::load();if(!$policy['ready'])throw new RuntimeException('Validated approved release configuration required');
        $batch=(int)($args['batch'] ?? 0);$policy+=BrowserFacePolicy::rollout($database,$branch,$batch,$policy,null,$actor);
        if(($policy['operating_mode'] ?? '')!=='LIVE')throw new RuntimeException('Exact authorized LIVE batch required');
        $cipher=new FaceEmbeddingCipher(FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY'),FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY_ID'));
        $service=new BrowserFaceService($db,$cipher,$actor,$branch,$policy);
        // Each sweep must see newly captured candidates; reusing the policy's
        // old journal key would replay an earlier completed sweep forever.
        $result=$service->run('activate_pending',['idempotency_key'=>'activation_'.substr($policy['report_sha256'],0,40).'_'.$branch.'_'.bin2hex(random_bytes(8))]);echo json_encode($result)."\n";return;
    }
    if($command==='retire-templates'){
        if(empty($args['apply']) || !is_string($args['authorized-by'] ?? null) || strlen($args['authorized-by'])>100 || trim($args['authorized-by'])==='')throw new InvalidArgumentException('Explicit --apply and --authorized-by required');
        $member=(int)($args['member'] ?? 0);$list=$args['template-ids'] ?? '';$fingerprint=$args['fingerprint'] ?? '';
        if($member<1 || !is_string($list) || !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D',$list) || !preg_match('/^[a-f0-9]{64}$/D',(string)$fingerprint))throw new InvalidArgumentException('Exact member/template IDs/fingerprint required');
        $ids=array_map('intval',explode(',',$list));sort($ids);if(count($ids)!==count(array_unique($ids)))throw new InvalidArgumentException('Duplicate template IDs');
        $lock='browser-face:'.substr(hash('sha256',$database),0,45);$s=$db->prepare('SELECT GET_LOCK(?,10) AS ok');$s->bind_param('s',$lock);$s->execute();if((int)$s->get_result()->fetch_assoc()['ok']!==1)throw new RuntimeException('Face mutation busy');
        try{$db->begin_transaction();$s=$db->prepare('SELECT ft.id,ft.model_version_id,ft.encrypted_embedding,ft.status,mv.preprocessing_version FROM face_template ft JOIN member m ON m.id=ft.member_id JOIN face_model_version mv ON mv.id=ft.model_version_id WHERE ft.member_id=? AND m.bid=? AND ft.id IN('.implode(',',$ids).') ORDER BY ft.id FOR UPDATE');$s->bind_param('ii',$member,$branch);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
            if(array_map('intval',array_column($rows,'id'))!==$ids)throw new RuntimeException('Template set/member/branch changed');
            if(count(array_filter($rows,fn($r)=>$r['status']==='REVOKED' && $r['encrypted_embedding']===''))===count($ids)){$db->commit();echo 'Templates already retired: '.implode(',',$ids)."\n";return;}
            $contract=BrowserFacePolicy::contract();foreach($rows as $r)if($r['status']!=='ACTIVE' || $r['preprocessing_version']===$contract['preprocessing_version'])throw new RuntimeException('Only inspected ACTIVE legacy templates can be retired');
            $s=$db->prepare("SELECT ft.id FROM face_template ft JOIN face_model_version mv ON mv.id=ft.model_version_id WHERE ft.member_id=? AND ft.status='ACTIVE' AND mv.preprocessing_version<>? ORDER BY ft.id FOR UPDATE");$s->bind_param('is',$member,$contract['preprocessing_version']);$s->execute();if(array_map('intval',array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'id'))!==$ids)throw new RuntimeException('Active legacy template set changed');
            $actual=hash('sha256',implode('|',array_map(fn($r)=>$r['id'].':'.$r['model_version_id'].':'.hash('sha256',$r['encrypted_embedding']),$rows)));if(!hash_equals($fingerprint,$actual))throw new RuntimeException('Template fingerprint changed');
            $by=$args['authorized-by'];$s=$db->prepare("UPDATE face_template SET status='REVOKED',revoked_at=NOW(),encrypted_embedding='',quality_summary=JSON_SET(COALESCE(quality_summary,JSON_OBJECT()),'$.retired_by',?,'$.retired_reason','USER_AUTHORIZED_LEGACY_RESET') WHERE member_id=? AND id IN(".implode(',',$ids).")");$s->bind_param('si',$by,$member);$s->execute();if($s->affected_rows!==count($ids))throw new RuntimeException('Retirement incomplete');
            $db->commit();echo 'Retired and erased legacy templates: '.implode(',',$ids).'; attendance and reference records preserved.' ."\n";
        }catch(Throwable $e){$db->rollback();throw $e;}finally{$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();}return;
    }
    if($command==='rollback-migration'){
        if(empty($args['apply']) || empty($args['authorized-by']))throw new InvalidArgumentException('Explicit --apply and --authorized-by required for rollback');
        $lock='browser-face:'.substr(hash('sha256',$database),0,45);$s=$db->prepare('SELECT GET_LOCK(?,10) AS ok');$s->bind_param('s',$lock);$s->execute();if((int)$s->get_result()->fetch_assoc()['ok']!==1)throw new RuntimeException('Face mutation busy');
        try{$db->begin_transaction();$id=(int)($args['migration-id'] ?? 0);$s=$db->prepare('SELECT * FROM face_web_migration WHERE id=? AND branch_id=? AND rolled_back_at IS NULL FOR UPDATE');$s->bind_param('ii',$id,$branch);$s->execute();$migration=$s->get_result()->fetch_assoc();if(!$migration)throw new RuntimeException('Migration missing or already rolled back');
            $member=(int)$migration['member_id'];$replacement=(int)$migration['replacement_template_id'];$s=$db->prepare("SELECT id FROM face_template WHERE member_id=? AND status='ACTIVE' FOR UPDATE");$s->bind_param('i',$member);$s->execute();$active=array_map('intval',array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'id'));if($active!==[$replacement])throw new RuntimeException('Enrollment changed since migration; rollback requires review');
            foreach(json_decode($migration['legacy_snapshot'],true,32,JSON_THROW_ON_ERROR) as $old){$oldId=(int)$old['template_id'];$s=$db->prepare("SELECT id FROM face_template WHERE id=? AND member_id=? AND status='SUPERSEDED' AND LENGTH(encrypted_embedding)>0 FOR UPDATE");$s->bind_param('ii',$oldId,$member);$s->execute();if(!$s->get_result()->num_rows)throw new RuntimeException('Legacy snapshot changed or erased; rollback aborted');}
            $s=$db->prepare("UPDATE face_template SET status='REVOKED',revoked_at=NOW() WHERE id=? AND member_id=?");$s->bind_param('ii',$replacement,$member);$s->execute();
            foreach(json_decode($migration['legacy_snapshot'],true) as $old){$oldId=(int)$old['template_id'];$s=$db->prepare("UPDATE face_template SET status='ACTIVE',revoked_at=NULL WHERE id=? AND member_id=?");$s->bind_param('ii',$oldId,$member);$s->execute();}
            $by=$args['authorized-by'];$s=$db->prepare('UPDATE face_web_migration SET rolled_back_at=NOW(),rolled_back_by=? WHERE id=?');$s->bind_param('si',$by,$id);$s->execute();
            $response=json_encode(['state'=>'MIGRATION_ROLLED_BACK','message'=>'Replacement rolled back; legacy compatibility gate restored']);$s=$db->prepare('UPDATE face_web_request SET response=? WHERE actor_id=? AND request_key=?');$s->bind_param('sis',$response,$migration['actor_id'],$migration['request_key']);$s->execute();$db->commit();echo "Replacement rolled back; historical attendance unchanged; incompatible-template gate restored.\n";
        }catch(Throwable $e){$db->rollback();throw $e;}finally{$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();}return;
    }
    throw new InvalidArgumentException('Commands: inspect-migration, retire-templates, export-calibration, purge-expired, export-shadow, purge-shadow, activate-pending, rollback-migration');
}
if(realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__){try{adminMain($argv);}catch(Throwable $error){fwrite(STDERR,$error instanceof InvalidArgumentException ? $error->getMessage()."\n" : "Operation failed; verify private configuration and schema. No biometric data logged.\n");exit(1);}}
