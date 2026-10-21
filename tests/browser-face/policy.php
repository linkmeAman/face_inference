<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/classes/services/BrowserFacePolicy.php';
$contract=BrowserFacePolicy::contract();
// Synthetic metadata exercises approval validation only; never installed.
$report=['source'=>'consented-live-cameras','blockers'=>[],'identities'=>20,'samples'=>100,
    'model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version'],
    'recommendations'=>['DUPLICATE_THRESHOLD'=>['threshold'=>.99],'ATTENDANCE_THRESHOLD'=>['threshold'=>.99]],
    'decision_evaluation'=>['version'=>'browser-face-decisions-v1','complete'=>true,'full_validation_batches'=>100,'validation'=>['counts'=>['known'=>50,'unknown'=>50]]]];
$reportFile=tempnam(sys_get_temp_dir(),'ccc_policy_report_');$policyFile=tempnam(sys_get_temp_dir(),'ccc_policy_test_');
file_put_contents($reportFile,json_encode($report));
$policy=['approved'=>true,'approved_by'=>'synthetic-unit-test','approved_at'=>'2026-10-09','independent_validation'=>true,'independent_workflow_validation'=>true,
    'source'=>$report['source'],'model_version'=>$report['model_version'],'preprocessing_version'=>$report['preprocessing_version'],
    'identities'=>20,'samples'=>100,'DUPLICATE_THRESHOLD'=>.99,'ATTENDANCE_THRESHOLD'=>.99,'report_path'=>$reportFile,'report_sha256'=>hash_file('sha256',$reportFile)];
$prior=getenv('FACE_WEB_CALIBRATION_FILE');
try{
    file_put_contents($policyFile,json_encode($policy));putenv('FACE_WEB_CALIBRATION_FILE='.$policyFile);
    if(!BrowserFacePolicy::load()['ready'])throw new RuntimeException('Valid synthetic metadata rejected');
    echo 'PASS report-bound approval contract'.PHP_EOL;
    foreach(['hash','version','source','counts','threshold','approval','workflow'] as $case){
        $changed=$policy;
        switch($case){case 'hash':$changed['report_sha256']=str_repeat('0',64);break;
            case 'version':$changed['preprocessing_version']='old';break;
            case 'source':$changed['source']='synthetic';break;
            case 'counts':$changed['identities']=2;break;
            case 'threshold':$changed['ATTENDANCE_THRESHOLD']=.5;break;
            case 'approval':$changed['approved']=false;break;
            case 'workflow':$changed['independent_workflow_validation']=false;break;}
        file_put_contents($policyFile,json_encode($changed));
        if(BrowserFacePolicy::load()['ready'])throw new RuntimeException('Invalid '.$case.' accepted');
        echo 'PASS rejected incompatible approval '.$case.PHP_EOL;
    }
    unset($report['decision_evaluation']);file_put_contents($reportFile,json_encode($report));$policy['report_sha256']=hash_file('sha256',$reportFile);file_put_contents($policyFile,json_encode($policy));
    if(BrowserFacePolicy::load()['ready'])throw new RuntimeException('Aggregate-only evidence accepted');echo "PASS rejected aggregate-only release evidence\n";
    $report=['source'=>'consented-live-cameras','model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version'],'identities'=>1,'samples'=>12,'recommendations'=>null,'provisional_pilot'=>['ready'=>true,'mode'=>'SINGLE_IDENTITY_LIVE_DEMO','functional_demo_only'=>true,'production_approved'=>false,'DUPLICATE_THRESHOLD'=>.99,'ATTENDANCE_THRESHOLD'=>.99]];
    file_put_contents($reportFile,json_encode($report));$policy['report_sha256']=hash_file('sha256',$reportFile);file_put_contents($policyFile,json_encode($policy));
    if(BrowserFacePolicy::load()['ready'])throw new RuntimeException('Provisional demo promoted to production');echo "PASS isolated single-identity demo cannot be promoted to production\n";
}finally{putenv($prior===false ? 'FACE_WEB_CALIBRATION_FILE' : 'FACE_WEB_CALIBRATION_FILE='.$prior);unlink($reportFile);unlink($policyFile);}
