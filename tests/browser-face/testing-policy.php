<?php
require __DIR__.'/policy.php';
function check($condition,$label){if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label,PHP_EOL;}
$contract=BrowserFacePolicy::contract();
$report=['test_fixture_only'=>true,'production_approved'=>false,'source'=>'consented-live-cameras','model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version'],'provisional_pilot'=>['production_approved'=>false,'genuine_trials'=>1,'expires_at'=>gmdate('c',time()+3600),'ATTENDANCE_THRESHOLD'=>.99,'DUPLICATE_THRESHOLD'=>.99]];
// Protected public metadata fixture; no face vectors/images or production configuration writes.
$path=tempnam('/tmp','ccc-testing-policy-');chmod($path,0600);file_put_contents($path,json_encode($report));
$c=['version'=>'browser-face-rollout-v1','enabled'=>true,'default_mode'=>'SHADOW','scopes'=>[], 'testing_evaluation'=>['enabled'=>true,'database'=>'ccc_database','branch_id'=>2,'report_path'=>$path,'report_sha256'=>hash_file('sha256',$path),'expires_at'=>gmdate('c',time()+3600)]];
try{
 $m=['ready'=>false,'ATTENDANCE_THRESHOLD'=>null,'DUPLICATE_THRESHOLD'=>null];$p=BrowserFacePolicy::rollout('ccc_database',2,27,$m,$c,3);
 check($p['operating_mode']==='TEST' && $p['testing_evaluation'] && $p['TEST_ATTENDANCE_THRESHOLD']===$report['provisional_pilot']['ATTENDANCE_THRESHOLD'],'protected evidence-bound Testing evaluation is enabled without production readiness');
 foreach([['another_tenant',2],['ccc_database',1],['ccc_database',4],['ccc_database',5]] as [$database,$branch])check(BrowserFacePolicy::rollout($database,$branch,27,$m,$c,3)['operating_mode']==='SHADOW','test rules cannot leak into another tenant or branch');
 check(BrowserFacePolicy::rollout('ccc_database',2,0,$m,$c,3)['operating_mode']==='CAPTURE_ONLY','no selected batch cannot open a test scanner');
 $bad=$c;$bad['testing_evaluation']['report_sha256']=str_repeat('0',64);check(BrowserFacePolicy::rollout('ccc_database',2,27,$m,$bad,3)['operating_mode']==='SHADOW','changed report cannot enable Testing decisions');
 $bad=$c;$bad['testing_evaluation']['expires_at']=gmdate('c',time()-1);check(BrowserFacePolicy::rollout('ccc_database',2,27,$m,$bad,3)['operating_mode']==='SHADOW','expired Testing configuration stops provisional recognition');
 $bad=$c;$bad['enabled']=false;check(BrowserFacePolicy::rollout('ccc_database',2,27,$m,$bad,3)['operating_mode']==='DISABLED','global rollback overrides Testing');
 $bad=$c;$bad['scopes']=[['database'=>'ccc_database','branch_id'=>2,'batch_id'=>27,'mode'=>'DISABLED']];check(BrowserFacePolicy::rollout('ccc_database',2,27,$m,$bad,3)['operating_mode']==='DISABLED','batch emergency stop overrides Testing');
 check(BrowserFacePolicy::load()['ready']===false,'Testing rollout never installs production calibration');
}finally{unlink($path);}
