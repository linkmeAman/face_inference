<?php
require_once __DIR__ . '/FaceAttendanceConfig.php';

final class BrowserFacePolicy
{
    /** Tenant/branch/batch readiness only; the controller enforces existing Attendance access.
     * The actor argument is retained for call compatibility, never as a face-specific access gate. */
    public static function rollout(string $database,int $branch,int $batch,array $matching,?array $configuration=null,int $actor=0): array
    {
        $legacy=['operating_mode'=>$batch>0?'SHADOW':'CAPTURE_ONLY','rollout_authorized'=>$batch>0,'rollout_reason'=>$batch>0?'':'CAPTURE_ONLY','rollout_batch_id'=>$batch,'capture_available'=>true];
        $disabled=['operating_mode'=>'DISABLED','rollout_authorized'=>false,'rollout_reason'=>'FIELD_RELEASE_DISABLED','rollout_batch_id'=>$batch,'capture_available'=>false];
        if($configuration===null){
            $path=FaceAttendanceConfig::getEnv('FACE_WEB_ROLLOUT_FILE');
            if($path==='')return $legacy;
            $root=realpath(dirname(__DIR__,2));$resolved=realpath($path);
            // A configured but missing/corrupt/private-file violation fails closed.
            if(!$resolved || strpos($resolved,$root.DIRECTORY_SEPARATOR)===0 || !is_readable($resolved) || (fileperms($resolved)&0007)!==0)return $disabled;
            $configuration=json_decode(file_get_contents($resolved),true);
        }
        if(!is_array($configuration) || ($configuration['version'] ?? '')!=='browser-face-rollout-v1')return $disabled;
        $controlled=array_key_exists('default_mode',$configuration);
        $default=$legacy;
        if($controlled){
            $defaultMode=$configuration['default_mode'];
            if(!in_array($defaultMode,['DISABLED','CAPTURE_ONLY','SHADOW'],true))return $disabled;
            $default=$defaultMode==='DISABLED'?$disabled:array_replace($legacy,['operating_mode'=>$batch>0?$defaultMode:'CAPTURE_ONLY']);
        }
        if(($configuration['enabled'] ?? true)!==true)return $disabled;
        // Branch-wide scope (batch_id 0), written by the administrator settings page. DISABLED is a branch
        // kill switch that wins over every batch scope and never expires; CAPTURE_ONLY/SHADOW set the branch
        // default for batches without their own scope. LIVE can never be branch-wide: it needs an exact batch.
        $branchMode='';
        foreach($configuration['scopes'] ?? [] as $scope){
            if(!is_array($scope) || ($scope['database'] ?? '')!==$database || ($scope['branch_id'] ?? null)!==$branch || ($scope['batch_id'] ?? null)!==0)continue;
            $mode=$scope['mode'] ?? '';
            if($mode==='DISABLED')return $disabled;
            if(empty($scope['approved_by']) || empty($scope['approved_at']) || strtotime($scope['expires_at'] ?? '')<=time() || !in_array($mode,['CAPTURE_ONLY','SHADOW'],true))break;
            $branchMode=$mode;$default=array_replace($legacy,['operating_mode'=>$batch>0?$mode:'CAPTURE_ONLY']);
            break;
        }
        $test=self::testing($database,$branch,$configuration['testing_evaluation'] ?? null);
        if($test && $default['operating_mode']!=='DISABLED' && $branchMode!=='CAPTURE_ONLY')$default=array_replace($default,$test,['operating_mode'=>$batch>0?'TEST':'CAPTURE_ONLY']);
        foreach($configuration['scopes'] ?? [] as $scope){
            if($batch<1 || !is_array($scope) || ($scope['database'] ?? '')!==$database || ($scope['branch_id'] ?? null)!==$branch || ($scope['batch_id'] ?? null)!==$batch)continue;
            if(($scope['mode'] ?? '')==='DISABLED')return $disabled;
            if(empty($scope['approved_by']) || empty($scope['approved_at']) || strtotime($scope['expires_at'] ?? '')<=time())return $default;
            $mode=$scope['mode'] ?? '';
            if(!in_array($mode,['CAPTURE_ONLY','SHADOW','LIVE'],true))return $default;
            if($mode==='LIVE' && (empty($matching['ready']) || empty($matching['report_sha256']) || !hash_equals((string)($scope['matching_report_sha256'] ?? ''),(string)$matching['report_sha256'])))return $default+['requested_mode'=>'LIVE'];
            if($test && $mode==='SHADOW')return array_replace($default,$test,['operating_mode'=>'TEST']);
            return ['operating_mode'=>$mode,'rollout_authorized'=>true,'rollout_reason'=>'','rollout_batch_id'=>$batch,'capture_available'=>true,
                'finalize_absences'=>$mode==='LIVE' && ($scope['finalization']['cutoff'] ?? '')==='SCHEDULED_SESSION_END' && !empty($scope['finalization']['approved_by']) && !empty($scope['finalization']['approved_at']),
                'rollout_scope_sha256'=>hash('sha256',json_encode($scope))];
        }
        return $default;
    }
    /** Explicit non-writing evaluation, never matching readiness or production activation. */
    private static function testing(string $database,int $branch,$c): array
    {
        if(!is_array($c) || ($c['enabled'] ?? false)!==true || $database!=='ccc_database' || $branch!==2 || ($c['database'] ?? '')!==$database || ($c['branch_id'] ?? null)!==$branch || strtotime($c['expires_at'] ?? '')<=time())return [];
        $path=realpath($c['report_path'] ?? '');$root=realpath(dirname(__DIR__,2));
        if(!$path || strpos($path,$root.DIRECTORY_SEPARATOR)===0 || !is_readable($path) || (fileperms($path)&0007)!==0 || !preg_match('/^[a-f0-9]{64}$/D',(string)($c['report_sha256'] ?? '')) || !hash_equals($c['report_sha256'],hash_file('sha256',$path)))return [];
        $r=json_decode(file_get_contents($path),true);$p=$r['provisional_pilot'] ?? [];$contract=self::contract();
        if(($r['production_approved'] ?? null)!==false || ($p['production_approved'] ?? null)!==false || ($r['source'] ?? '')!=='consented-live-cameras' || ($r['model_version'] ?? '')!==$contract['model_version'] || ($r['preprocessing_version'] ?? '')!==$contract['preprocessing_version'] || ($p['genuine_trials'] ?? 0)<1 || strtotime($p['expires_at'] ?? '')<=time())return [];
        foreach(['DUPLICATE_THRESHOLD','ATTENDANCE_THRESHOLD'] as $key)if(!is_numeric($p[$key] ?? null) || !is_finite((float)$p[$key]) || $p[$key]<-1 || $p[$key]>1)return [];
        return ['testing_evaluation'=>true,'test_report_sha256'=>$c['report_sha256'],'TEST_DUPLICATE_THRESHOLD'=>(float)$p['DUPLICATE_THRESHOLD'],'TEST_ATTENDANCE_THRESHOLD'=>(float)$p['ATTENDANCE_THRESHOLD']];
    }
    public const SETUP_REQUIRED_MESSAGE = 'Face scanning is not ready yet. Please contact the administrator.';
    public static function installed(mysqli $db): bool
    {
        $contract=self::contract();
        $stmt=$db->prepare('SELECT id FROM face_model_version WHERE model_checksum=? AND preprocessing_version=? AND status IN (\'ACTIVE\',\'PENDING_CALIBRATION\') LIMIT 1');
        $stmt->bind_param('ss',$contract['recognizer_source_sha256'],$contract['preprocessing_version']);$stmt->execute();
        $found=$stmt->get_result()->num_rows>0;$stmt->close();return $found;
    }
    public static function contract(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/pf/js/browser-face/contract.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function load(): array
    {
        $contract = self::contract();
        $file = FaceAttendanceConfig::getEnv('FACE_WEB_CALIBRATION_FILE');
        $evidence = $file !== '' && is_readable($file) ? json_decode(file_get_contents($file), true) : null;
        $evidence=is_array($evidence) ? $evidence : [];
        $reportPath=$evidence['report_path'] ?? '';
        $report=null;
        if(is_string($reportPath) && $reportPath!=='' && is_readable($reportPath)
            && preg_match('/^[a-f0-9]{64}$/D',(string)($evidence['report_sha256'] ?? ''))
            && hash_equals($evidence['report_sha256'],hash_file('sha256',$reportPath))) {
            $report=json_decode(file_get_contents($reportPath),true);
        }
        $decisions=$report['decision_evaluation'] ?? [];
        $valid=is_array($report) && empty($report['blockers']) && ($report['source'] ?? '')==='consented-live-cameras'
            && ($decisions['version'] ?? '')==='browser-face-decisions-v1' && ($decisions['complete'] ?? false)===true
            && ($decisions['full_validation_batches'] ?? 0)>0 && ($decisions['validation']['counts']['known'] ?? 0)>0
            && ($decisions['validation']['counts']['unknown'] ?? 0)>0;
        foreach(['model_version','preprocessing_version','identities','samples'] as $field)
            $valid=$valid && isset($report[$field],$evidence[$field]) && $report[$field]===$evidence[$field];
        foreach(['DUPLICATE_THRESHOLD','ATTENDANCE_THRESHOLD'] as $field) {
            $recommended=$report['recommendations'][$field]['threshold'] ?? null;
            $valid=$valid && is_numeric($recommended) && isset($evidence[$field]) && abs((float)$recommended-(float)$evidence[$field])<1e-12;
        }
        if(!$valid)$evidence['approved']=false;
        return self::validate($contract,$evidence);
    }

    public static function validate(array $contract, array $evidence): array
    {
        $valid = ($evidence['approved'] ?? false) === true
            && !empty($evidence['approved_by']) && !empty($evidence['approved_at'])
            && ($evidence['model_version'] ?? '') === $contract['model_version']
            && ($evidence['preprocessing_version'] ?? '') === $contract['preprocessing_version']
            && ($evidence['identities'] ?? 0) >= 20 && ($evidence['samples'] ?? 0) >= 100
            && ($evidence['independent_validation'] ?? false) === true
            && ($evidence['independent_workflow_validation'] ?? false) === true
            && preg_match('/^[a-f0-9]{64}$/D',(string)($evidence['report_sha256'] ?? ''))
            && ($evidence['source'] ?? '') === 'consented-live-cameras';
        foreach (['DUPLICATE_THRESHOLD', 'ATTENDANCE_THRESHOLD'] as $name) {
            $value = $evidence[$name] ?? null;
            $valid = $valid && (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= -1 && $value <= 1;
        }
        return ['ready' => (bool)$valid, 'reason' => $valid ? '' : 'CALIBRATION_REQUIRED',
            'DUPLICATE_THRESHOLD' => $valid ? (float)$evidence['DUPLICATE_THRESHOLD'] : null,
            'ATTENDANCE_THRESHOLD' => $valid ? (float)$evidence['ATTENDANCE_THRESHOLD'] : null,
            'report_sha256'=>$valid ? $evidence['report_sha256'] : null];
    }
}
