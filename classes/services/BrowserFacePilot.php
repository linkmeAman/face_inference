<?php
require_once __DIR__.'/BrowserFacePolicy.php';

/** Expiring, test-only connection. No production policy is changed. */
final class BrowserFacePilot
{
    public static function load(string $source,int $branch,?string $file=null):array
    {
        $file=$file ?? FaceAttendanceConfig::getEnv('FACE_WEB_PILOT_FILE');
        $root=realpath(dirname(__DIR__,2));$path=$file!==''?realpath($file):false;
        if(!$path || strpos($path,$root.DIRECTORY_SEPARATOR)===0 || !is_readable($path) || (fileperms($path)&0007)!==0)throw new RuntimeException('Test camera is not available. Contact the administrator.');
        $config=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        self::scope($config,$source,$branch);
        return $config;
    }

    public static function scope(array $c,string $source,int $branch):void
    {
        if($source!=='ccc_database' || $branch!==2 || ($c['source_database'] ?? '')!==$source || ($c['branch_id'] ?? 0)!==$branch
            || ($c['batch_id'] ?? 0)!==33 || ($c['member_ids'] ?? [])!==[623,624,625] || ($c['contact_ids'] ?? [])!==[791,792,793]
            || ($c['item_ids'] ?? [])!==[1188,1189,1190] || !preg_match('/^ccc_database_face_pilot_[a-f0-9]{8}$/D',(string)($c['database'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($c['owner_token'] ?? '')) || strtotime($c['expires_at'] ?? '')<=time()
            || strtotime($c['expires_at'])>time()+8*86400)throw new RuntimeException('Pilot scope or expiry is invalid');
    }

    public static function verify(mysqli $db,array $c):void
    {
        self::scope($c,'ccc_database',2);
        if($db->query('SELECT DATABASE()')->fetch_row()[0]!==$c['database'])throw new RuntimeException('Pilot connection is not isolated');
        $row=$db->query('SELECT token_hash,source_database,branch_id,batch_id FROM face_pilot_owner')->fetch_assoc();
        if(!$row || !hash_equals($row['token_hash'],hash('sha256',$c['owner_token'])) || $row['source_database']!==$c['source_database'] || (int)$row['branch_id']!==2 || (int)$row['batch_id']!==33)throw new RuntimeException('Pilot ownership verification failed');
        foreach(['member'=>'member_ids','contact'=>'contact_ids','invoice_item'=>'item_ids'] as $table=>$field){$actual=array_map('intval',array_column($db->query("SELECT id FROM `$table` ORDER BY id")->fetch_all(MYSQLI_ASSOC),'id'));if($actual!==$c[$field])throw new RuntimeException('Pilot contains identities outside its allowlist');}
        if((int)$db->query('SELECT COUNT(*) FROM member WHERE bid<>2 OR park<>0 OR status<>1')->fetch_row()[0]!==0
            || (int)$db->query('SELECT COUNT(*) FROM batch WHERE id<>33 OR bid<>2')->fetch_row()[0]!==0
            || (int)$db->query('SELECT COUNT(*) FROM batch')->fetch_row()[0]!==1
            || (int)$db->query('SELECT COUNT(*) FROM session WHERE batch_id<>33')->fetch_row()[0]!==0
            || (int)$db->query('SELECT COUNT(*) FROM invoice_item WHERE batch_id<>33 OR item_amount<>0 OR item_total<>0')->fetch_row()[0]!==0)throw new RuntimeException('Pilot attendance scope changed');
        $name=$c['database'];
        $s=$db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=?');$s->bind_param('s',$name);$s->execute();if((int)$s->get_result()->fetch_row()[0])throw new RuntimeException('Pilot triggers are not allowed');
        $s=$db->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_SCHEMA IS NOT NULL AND REFERENCED_TABLE_SCHEMA<>?');$s->bind_param('ss',$name,$name);$s->execute();if((int)$s->get_result()->fetch_row()[0])throw new RuntimeException('Pilot references another database');
        $s=$db->prepare('SELECT TABLE_NAME,VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=?');$s->bind_param('s',$name);$s->execute();$views=$s->get_result()->fetch_all(MYSQLI_ASSOC);
        if(count($views)!==2)throw new RuntimeException('Pilot view contract changed');
        foreach($views as $view){if(!in_array($view['TABLE_NAME'],['invoice_invoiceitem_view','invoice_invoiceitem_module_view'],true) || !$view['VIEW_DEFINITION'])throw new RuntimeException('Unexpected pilot view');
            preg_match_all('/\b(?:from|join)\s*\(*\s*`([^`]+)`\s*\.\s*`([^`]+)`/i',$view['VIEW_DEFINITION'],$matches);
            if(count($matches[1])!==2 || array_diff($matches[2],[$view['TABLE_NAME'].'_seed','invoice_item']))throw new RuntimeException('Pilot view dependency contract changed');
            foreach($matches[1] as $schema)if($schema!==$name)throw new RuntimeException('Pilot view accesses another database');
            if((int)$db->query("SELECT COUNT(*) FROM `".$view['TABLE_NAME']."_seed` WHERE contact_id NOT IN(791,792,793) OR batch_id<>33 OR item_id NOT IN(1188,1189,1190)")->fetch_row()[0])throw new RuntimeException('Pilot membership labels are outside the allowlist');}
        foreach(['face_template','biometric_consent','face_web_calibration','face_web_enrollment','attendance_scan_roster'] as $table)if((int)$db->query("SELECT COUNT(*) FROM `$table` WHERE member_id NOT IN(623,624,625)")->fetch_row()[0])throw new RuntimeException('Pilot biometric identity outside the allowlist');
        if((int)$db->query('SELECT COUNT(*) FROM attendance WHERE batch_id<>33 OR contact_id NOT IN(791,792,793)')->fetch_row()[0]
            || (int)$db->query('SELECT COUNT(*) FROM attendance_event WHERE batch_id<>33 OR branch_id<>2 OR person_type<>\'M\' OR person_id NOT IN(791,792,793)')->fetch_row()[0])throw new RuntimeException('Pilot attendance identity outside the allowlist');
        $policy=self::policy($c);
        if($policy['ready'] && ($policy['mode'] ?? '')==='SINGLE_IDENTITY_LIVE_DEMO'){
            $reference=$policy['reference'];
            $rows=$db->query("SELECT ft.id AS template_id,ft.member_id,ft.embedding_dimension,e.id AS enrollment_id,e.calibration_id,e.capture_session,e.branch_id,e.state,mv.model_name,mv.preprocessing_version,SHA2(ft.encrypted_embedding,256) AS template_sha256,SHA2(e.encrypted_template,256) AS enrollment_sha256 FROM face_template ft LEFT JOIN face_web_enrollment e ON e.active_template_id=ft.id JOIN face_model_version mv ON mv.id=ft.model_version_id WHERE ft.status='ACTIVE'")->fetch_all(MYSQLI_ASSOC);
            if(count($rows)!==1)throw new RuntimeException('Test scanner reference population changed');
            $row=$rows[0];$contract=BrowserFacePolicy::contract();
            foreach(['template_id','member_id','enrollment_id','calibration_id'] as $field)if((int)$row[$field]!==$reference[$field])throw new RuntimeException('Test scanner reference identity changed');
            if($row['state']!=='ACTIVE' || (int)$row['branch_id']!==2 || (int)$row['embedding_dimension']!==128
                || $row['model_name']!==$contract['model_version'] || $row['preprocessing_version']!==$contract['preprocessing_version']
                || $row['capture_session']!==$reference['capture_session'] || !hash_equals($reference['template_sha256'],$row['template_sha256'])
                || !hash_equals($reference['enrollment_sha256'],$row['enrollment_sha256']))throw new RuntimeException('Test scanner original reference changed');
        }
    }

    public static function policy(array $c):array
    {
        $contract=BrowserFacePolicy::contract();$path=$c['report_path'] ?? '';
        $report=is_string($path) && is_readable($path) && hash_equals((string)($c['report_sha256'] ?? ''),hash_file('sha256',$path)) ? json_decode(file_get_contents($path),true) : [];
        $p=$report['provisional_pilot'] ?? [];$threshold=$p['threshold'] ?? null;
        $ready=($p['ready'] ?? false)===true && ($p['production_approved'] ?? true)===false && ($report['source'] ?? '')==='consented-live-cameras'
            && ($report['model_version'] ?? '')===$contract['model_version'] && ($report['preprocessing_version'] ?? '')===$contract['preprocessing_version']
            && ($report['dataset_databases'] ?? [])===[$c['database']] && ($report['dataset_branches'] ?? [])===[2]
            && count($report['identity_ids'] ?? [])>=2 && !array_diff(array_map('intval',$report['identity_ids']),$c['member_ids'])
            && ($p['identities'] ?? 0)>=2 && ($p['genuine_trials'] ?? 0)>0 && ($p['impostor_trials'] ?? 0)>0
            && (is_int($threshold) || is_float($threshold)) && is_finite((float)$threshold) && $threshold>=-1 && $threshold<=1;
        $result=['ready'=>$ready,'reason'=>$ready?'':'PILOT_EVIDENCE_REQUIRED','DUPLICATE_THRESHOLD'=>$ready?$threshold:null,'ATTENDANCE_THRESHOLD'=>$ready?$threshold:null,'report_sha256'=>$c['report_sha256'] ?? null,'isolated_pilot'=>true];
        if(($p['mode'] ?? '')!=='SINGLE_IDENTITY_LIVE_DEMO')return $result;
        // Explicit functional-demo authorization is separate from biometric
        // validation. It can never activate another identity or production.
        self::scope($c,'ccc_database',2);
        $reference=$p['reference'] ?? [];$identities=$report['identity_ids'] ?? [];
        $private=realpath($path);$root=realpath(dirname(__DIR__,2));
        $ready=$private && strpos($private,$root.DIRECTORY_SEPARATOR)!==0 && (fileperms($private)&0007)===0
            && ($p['ready'] ?? false)===true && ($p['functional_demo_only'] ?? false)===true && ($p['production_approved'] ?? true)===false
            && is_string($p['approved_by'] ?? null) && trim($p['approved_by'])!==''
            && strtotime($p['expires_at'] ?? '')>time() && strtotime($p['expires_at'])<=strtotime($c['expires_at'])
            && ($report['source'] ?? '')==='consented-live-cameras' && ($report['model_version'] ?? '')===$contract['model_version']
            && ($report['preprocessing_version'] ?? '')===$contract['preprocessing_version']
            && ($report['dataset_databases'] ?? [])===[$c['database']] && ($report['dataset_branches'] ?? [])===[2]
            && count($identities)===1 && is_int($identities[0]) && in_array($identities[0],$c['member_ids'],true)
            && ($p['identities'] ?? 0)===1 && ($p['genuine_trials'] ?? 0)>0 && ($reference['member_id'] ?? null)===$identities[0];
        foreach(['template_id','enrollment_id','calibration_id'] as $field)$ready=$ready && is_int($reference[$field] ?? null) && $reference[$field]>0;
        foreach(['template_sha256','enrollment_sha256'] as $field)$ready=$ready && preg_match('/^[a-f0-9]{64}$/D',(string)($reference[$field] ?? ''))===1;
        $ready=$ready && preg_match('/^[A-Za-z0-9_-]{16,80}$/D',(string)($reference['capture_session'] ?? ''))===1;
        foreach(['DUPLICATE_THRESHOLD','ATTENDANCE_THRESHOLD'] as $field){$t=$p[$field] ?? null;$ready=$ready && (is_int($t)||is_float($t)) && is_finite((float)$t) && $t>=-1 && $t<=1;}
        return ['ready'=>(bool)$ready,'reason'=>$ready?'':'PILOT_DEMO_AUTHORIZATION_REQUIRED','DUPLICATE_THRESHOLD'=>$ready?$p['DUPLICATE_THRESHOLD']:null,'ATTENDANCE_THRESHOLD'=>$ready?$p['ATTENDANCE_THRESHOLD']:null,
            'report_sha256'=>$c['report_sha256'] ?? null,'isolated_pilot'=>true,'mode'=>'SINGLE_IDENTITY_LIVE_DEMO','reference'=>$reference,'matching_member_ids'=>$ready?$identities:[],
            'activation_member_ids'=>[],'notice'=>'Practice only: one approved test face. Matching settings are unvalidated; real attendance is unaffected.'];
    }

    public static function request(array $c,string $action,array $payload):void
    {
        if(isset($payload['person_id']) && !in_array($payload['person_id'],$c['member_ids'],true))throw new InvalidArgumentException('Only consenting test members are allowed');
        if(isset($payload['batch_id']) && (int)$payload['batch_id']!==$c['batch_id'])throw new InvalidArgumentException('Select the test batch');
        if(isset($payload['evaluation_context']['batch_id']) && (int)$payload['evaluation_context']['batch_id']!==$c['batch_id'])throw new InvalidArgumentException('Select the test batch');
        if(in_array($action,['migrate','calibrate','migration_status'],true) && $action!=='migration_status')throw new InvalidArgumentException('This operation is unavailable in the isolated pilot');
    }
}
