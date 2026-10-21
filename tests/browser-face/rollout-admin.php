<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Branch-wide policy scopes and the administrator rollout writer. Temporary files only; no database,
// no biometric data and never the production rollout file.
require_once dirname(__DIR__,2).'/classes/services/BrowserFaceRolloutAdmin.php';
function check($condition,$label){if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
function rm(string $dir){foreach(glob($dir.'/{,.}*',GLOB_BRACE) ?: [] as $f){if(in_array(basename($f),['.','..'],true))continue;is_dir($f)?rm($f):@unlink($f);}@rmdir($dir);}

$T='ccc_database';$M_NOT=['ready'=>false];$M_OK=['ready'=>true,'report_sha256'=>str_repeat('a',64)];
$future=gmdate('c',time()+3600);$past=gmdate('c',time()-3600);
$scope=fn(int $branch,int $batch,string $mode,array $extra=[])=>array_replace(['database'=>$T,'branch_id'=>$branch,'batch_id'=>$batch,'mode'=>$mode,'approved_by'=>'admin:1','approved_at'=>gmdate('c'),'expires_at'=>$future],$extra);
$cfg=fn(array $scopes=[],array $more=[])=>array_replace(['version'=>'browser-face-rollout-v1','enabled'=>true,'default_mode'=>'SHADOW','scopes'=>$scopes],$more);
$mode=fn(array $config,int $branch,int $batch,array $matching=null,string $db=null)=>BrowserFacePolicy::rollout($db ?? $T,$branch,$batch,$matching ?? $M_NOT,$config)['operating_mode'];

// ---------------------------------------------------------------- policy: branch-wide scopes
check($mode($cfg(),4,10)==='SHADOW','unscoped batch keeps the global SHADOW default');
$killed=$cfg([$scope(4,0,'DISABLED'),$scope(4,11,'SHADOW')]);
check($mode($killed,4,10)==='DISABLED' && $mode($killed,4,0)==='DISABLED','branch-wide DISABLED stops every batch including new ones and batch-less requests');
check($mode($killed,4,11)==='DISABLED','branch-wide DISABLED wins over a batch scope');
check($mode($killed,5,10)==='SHADOW' && $mode($killed,4,10,null,'another_tenant')==='SHADOW','branch-wide DISABLED never crosses branches or tenants');
$killedForever=$cfg([['database'=>$T,'branch_id'=>4,'batch_id'=>0,'mode'=>'DISABLED','approved_by'=>'admin:1','approved_at'=>$past]]);
check($mode($killedForever,4,10)==='DISABLED','a branch DISABLED scope without expiry never expires');
$capture=$cfg([$scope(4,0,'CAPTURE_ONLY'),$scope(4,11,'SHADOW')]);
check($mode($capture,4,10)==='CAPTURE_ONLY' && $mode($capture,4,0)==='CAPTURE_ONLY','branch-wide CAPTURE_ONLY sets the branch default');
check($mode($capture,4,11)==='SHADOW','a batch scope overrides the branch default');
check($mode($cfg([$scope(4,0,'LIVE')]),4,10,$M_OK)==='SHADOW','a branch-wide LIVE scope is ignored (LIVE needs an exact batch)');
check($mode($cfg([$scope(4,0,'SHADOW',['expires_at'=>$past])],['default_mode'=>'DISABLED']),4,10)==='DISABLED','an expired branch scope is ignored');
check($mode($cfg([$scope(4,0,'SHADOW')],['default_mode'=>'DISABLED']),4,10)==='SHADOW','a branch SHADOW scope can enable a branch under a DISABLED default');
check($mode($cfg([$scope(4,0,'SHADOW')],['enabled'=>false]),4,10)==='DISABLED','the global stop still beats a branch scope');
check($mode($cfg([$scope(4,0,'SHADOW',['approved_by'=>''])],['default_mode'=>'DISABLED']),4,10)==='DISABLED','a branch scope without approval is ignored');

// ---------------------------------------------------------------- administrator writer
$dir=sys_get_temp_dir().'/ccc-rollout-admin-'.bin2hex(random_bytes(6));mkdir($dir,0770);mkdir($dir.'/log',0770);
$file=$dir.'/rollout.json';$logFile=$dir.'/log/events.jsonl';
$prior=[getenv('FACE_WEB_ROLLOUT_FILE'),getenv('FACE_WEB_EVENT_LOG')];
putenv('FACE_WEB_ROLLOUT_FILE='.$file);putenv('FACE_WEB_EVENT_LOG='.$logFile);
register_shutdown_function(function() use($dir,$prior){putenv($prior[0]===false?'FACE_WEB_ROLLOUT_FILE':'FACE_WEB_ROLLOUT_FILE='.$prior[0]);putenv($prior[1]===false?'FACE_WEB_EVENT_LOG':'FACE_WEB_EVENT_LOG='.$prior[1]);rm($dir);});
$seed=function(array $config) use($file){file_put_contents($file,json_encode($config,JSON_PRETTY_PRINT));chmod($file,0660);};
$sha=fn()=>hash_file('sha256',$file);
$events=[];$audit=function(string $e,array $m) use(&$events){$events[]=['event'=>$e]+$m;return true;};
$admin=new BrowserFaceRolloutAdmin($file);
$request=fn(array $r)=>$admin->apply($r+['admin_id'=>7,'database'=>$T,'matching'=>$M_NOT,'allowed_branches'=>[4,5],'valid_batches'=>[4=>[10,11,12],5=>[20,21]],'audit'=>$audit]);
$disk=fn()=>json_decode(file_get_contents($file),true);
$names=function() use(&$events){return array_column($events,'event');};

$seed($cfg([],['testing_evaluation'=>['enabled'=>true,'branch_id'=>2],'future_key'=>['keep'=>true]]));
check($admin->read()['ok'] && $admin->writable(),'the existing rollout file is readable and its directory writable');

// 1. ordinary change, effective result confirmed through the policy
$r=$request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>10],['branch_id'=>4,'batch_id'=>11]],'enrollment'=>true,'scanning'=>false]);
check($r['saved'] && $r['changed'] && $r['code']==='SAVED' && $r['message']==='Changes Saved','enrollment-only change is saved and reported as saved');
check($mode($disk(),4,10)==='CAPTURE_ONLY' && $mode($disk(),4,11)==='CAPTURE_ONLY' && $mode($disk(),4,12)==='SHADOW','the change applies to exactly the selected batches');
check(($disk()['future_key']['keep'] ?? false)===true && isset($disk()['testing_evaluation']) && $disk()['default_mode']==='SHADOW','unrelated settings, default mode and unknown keys are preserved');
check((fileperms($file)&0777)===0660 && (fileperms($file)&0007)===0,'the saved file has no world access (0660)');
check(count(glob($dir.'/history/rollout-*.json'))===1 && $r['history_ok'],'a history copy of the saved configuration is kept');
check(in_array('face_mode_changed',$names(),true),'the change is audited as face_mode_changed');
// history retention: bounded, newest copies kept, baseline never pruned
file_put_contents($dir.'/history/rollout-baseline-20200101T000000Z.json','{}');
for($i=0;$i<BrowserFaceRolloutAdmin::HISTORY_KEEP+20;$i++)file_put_contents($dir.'/history/rollout-2020'.sprintf('%04d',$i).'01T000000Z-'.sprintf('%016x',$i).'.json','{}');
$r2=$request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>12]],'enrollment'=>true,'scanning'=>true]);
$kept=glob($dir.'/history/rollout-[0-9]*T[0-9]*Z-*.json');
check($r2['saved'] && count($kept)===BrowserFaceRolloutAdmin::HISTORY_KEEP,'history copies are bounded to the retention limit');
check(is_file($dir.'/history/rollout-baseline-20200101T000000Z.json') && count(array_filter($kept,fn($f)=>strpos($f,gmdate('Y'))!==false))>=1,'retention keeps the newest copies and the baseline copy');
$e=$events[0];check($e['actor_id']===7 && $e['previous_mode']==='SHADOW' && $e['new_mode']==='CAPTURE_ONLY' && $e['persistence_succeeded']===true && preg_match('/^[a-f0-9]{16}$/',$e['request_id']) && $e['tenant']===$T,'audit carries administrator, previous and new setting, outcome, tenant and correlation id');

// 2. repeating the same request changes nothing
$before=$sha();$events=[];
$r=$request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>false]);
check($r['saved'] && !$r['changed'] && $r['code']==='NO_CHANGE' && $sha()===$before && !$events,'a repeated request writes nothing and logs nothing');

// 3. disable / re-enable events
$events=[];$request(['action'=>'stop','targets'=>[['branch_id'=>4,'batch_id'=>10]]]);
check($mode($disk(),4,10)==='DISABLED' && in_array('face_feature_disabled',$names(),true),'stopping a batch disables it and logs face_feature_disabled');
$events=[];$request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>true]);
check($mode($disk(),4,10)==='SHADOW' && in_array('face_feature_enabled',$names(),true),'re-enabling logs face_feature_enabled');

// 4. branch-wide
$request(['action'=>'stop','targets'=>[['branch_id'=>5,'batch_id'=>0]]]);
check($mode($disk(),5,20)==='DISABLED' && $mode($disk(),5,999)==='DISABLED' && $mode($disk(),4,12)==='SHADOW','disabling a branch stops all its batches (even future ones) and no other branch');
check($admin->apply(['action'=>'reset','admin_id'=>7,'database'=>$T,'matching'=>$M_NOT,'allowed_branches'=>[4,5],'valid_batches'=>[4=>[10],5=>[20]],'targets'=>[['branch_id'=>5,'batch_id'=>0]],'audit'=>$audit])['saved'] && $mode($disk(),5,20)==='SHADOW','resetting a branch returns it to the default');

// 5. LIVE can never be forced
$before=$sha();$events=[];
$r=$request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>true,'automatic'=>true,'live_blockers'=>[4=>[10=>'']]]);
check(!$r['saved'] && $r['code']==='MATCHING_NOT_APPROVED' && $sha()===$before,'LIVE is refused without approved matching and nothing is written');
check(in_array('face_rollout_rejected',$names(),true) && $events[0]['error_code']==='MATCHING_NOT_APPROVED' && $events[0]['persistence_succeeded']===false,'the refusal is audited as face_rollout_rejected');
$live=fn(array $blockers,array $targets=[['branch_id'=>4,'batch_id'=>10]])=>$request(['action'=>'set','targets'=>$targets,'enrollment'=>true,'scanning'=>true,'automatic'=>true,'matching'=>$M_OK,'live_blockers'=>$blockers]);
$r=$live([4=>[10=>'NO_ACTIVE_ENROLLMENTS']]);check(!$r['saved'] && $r['code']==='NO_ACTIVE_ENROLLMENTS' && $sha()===$before,'LIVE is refused for a batch without active enrollments');
$r=$live([4=>[10=>'TESTING_BRANCH']]);check(!$r['saved'] && $r['code']==='TESTING_BRANCH','LIVE is refused on a branch still in testing');
$r=$live([]);check(!$r['saved'] && $r['code']==='LIVE_UNVERIFIED' && $sha()===$before,'LIVE is refused when the batch was not checked');
$r=$live([4=>[0=>'']],[['branch_id'=>4,'batch_id'=>0]]);check(!$r['saved'] && $r['code']==='LIVE_REQUIRES_BATCH','LIVE cannot be applied to a whole branch');
$r=$live([4=>[10=>'',11=>'NO_ACTIVE_ENROLLMENTS']],[['branch_id'=>4,'batch_id'=>10],['branch_id'=>4,'batch_id'=>11]]);
check(!$r['saved'] && $mode($disk(),4,10,$M_OK)==='SHADOW','one blocked batch rejects the whole bulk LIVE request');
$events=[];$r=$live([4=>[10=>'']]);
check($r['saved'] && $mode($disk(),4,10,$M_OK)==='LIVE','LIVE is saved only when the policy confirms it is effective');
$liveScope=array_values(array_filter($disk()['scopes'],fn($s)=>$s['batch_id']===10))[0];
check($liveScope['matching_report_sha256']===str_repeat('a',64) && $liveScope['approved_by']==='admin:7' && strtotime($liveScope['expires_at'])>time()+80*86400 && strtotime($liveScope['expires_at'])<time()+100*86400,'the LIVE scope records the approving administrator, the report hash and a 90-day expiry');
check($mode($disk(),4,10,['ready'=>true,'report_sha256'=>str_repeat('b',64)])==='SHADOW' && $mode($disk(),4,10)==='SHADOW','LIVE stops being effective if the approved report changes or matching is no longer ready');
check(!in_array('face_rollout_rolled_back',$names(),true),'turning LIVE on is not logged as a rollback');
$events=[];$r=$request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>true,'automatic'=>false,'matching'=>$M_OK]);
check($r['saved'] && $mode($disk(),4,10,$M_OK)==='SHADOW' && in_array('face_rollout_rolled_back',$names(),true),'switching LIVE back to SHADOW is saved and logged as face_rollout_rolled_back');
$live([4=>[10=>'']]);$events=[];
$r=$request(['action'=>'stop','targets'=>[['branch_id'=>4,'batch_id'=>10]],'matching'=>$M_OK]);
check($r['saved'] && $mode($disk(),4,10,$M_OK)==='DISABLED' && in_array('face_rollout_rolled_back',$names(),true),'stopping a LIVE batch disables it immediately and logs face_rollout_rolled_back');

// 6. validation is all-or-nothing and scoped
$before=$sha();
$r=$request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>12],['branch_id'=>4,'batch_id'=>999]],'enrollment'=>true,'scanning'=>false]);
check(!$r['saved'] && $r['code']==='BATCH_NOT_FOUND' && $sha()===$before,'an unknown batch rejects the whole request');
$r=$request(['action'=>'set','targets'=>[['branch_id'=>9,'batch_id'=>0]],'enrollment'=>true,'scanning'=>false]);
check(!$r['saved'] && $r['code']==='BRANCH_NOT_AUTHORIZED' && $sha()===$before,'a branch the administrator cannot access is rejected');
check($request(['action'=>'set','targets'=>[],'enrollment'=>true])['code']==='NOTHING_SELECTED' && $request(['action'=>'bogus'])['code']==='INVALID_REQUEST' && $request(['action'=>'set','admin_id'=>0,'targets'=>[['branch_id'=>4,'batch_id'=>10]]])['code']==='INVALID_REQUEST','missing selection, unknown action and missing administrator are rejected');
check($request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>false,'scanning'=>true])['code']==='SCANNING_REQUIRES_ENROLLMENT' && $request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>false,'automatic'=>true])['code']==='SCANNING_REQUIRES_ENROLLMENT','scanning without enrollment, and automatic without scanning, are refused');
check($request(['action'=>'set','database'=>'bad name;','targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>true])['code']==='INVALID_REQUEST' && $sha()===$before,'a malformed tenant name is rejected');
$other=['database'=>'another_tenant','branch_id'=>4,'batch_id'=>10,'mode'=>'DISABLED','approved_by'=>'admin:1','approved_at'=>gmdate('c')];
$seed($cfg([$other]));$request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>false]);
check(in_array($other,$disk()['scopes'],true),'another tenant\'s scope is left untouched');

// 7. stale form and duplicate scopes (the CLI emergency stop prepends)
$seed($cfg([$scope(4,10,'DISABLED'),$scope(4,10,'DISABLED',['approved_by'=>'cli'])]));
check($mode($disk(),4,10)==='DISABLED','a CLI-style duplicate emergency scope disables the batch');
$stale=$sha();$request(['action'=>'reset','targets'=>[['branch_id'=>4,'batch_id'=>10]]]);
check($mode($disk(),4,10)==='SHADOW' && count(array_filter($disk()['scopes'],fn($s)=>$s['batch_id']===10))===0,'a reset removes every duplicate scope for that batch');
$before=$sha();$r=$request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>11]],'enrollment'=>true,'scanning'=>false,'expected_sha'=>$stale]);
check(!$r['saved'] && $r['code']==='CONFIG_CHANGED' && $sha()===$before,'a form built from an older configuration cannot overwrite a newer one');
$r=$request(['action'=>'set','targets'=>[['branch_id'=>4,'batch_id'=>11]],'enrollment'=>true,'scanning'=>false,'expected_sha'=>$sha()]);
check($r['saved'],'a form built from the current configuration is accepted');

// 8. persistence failures never look like success
$seed($cfg());$before=$sha();$events=[];
$failing=new BrowserFaceRolloutAdmin($file,function(){throw new RuntimeException('disk full');});
$r=$failing->apply(['action'=>'set','admin_id'=>7,'database'=>$T,'matching'=>$M_NOT,'allowed_branches'=>[4],'valid_batches'=>[4=>[10]],'targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>false,'audit'=>$audit]);
check(!$r['saved'] && $r['code']==='SAVE_FAILED' && $r['message']==='Unable to Save' && $sha()===$before && $events[0]['event']==='face_rollout_rejected' && $events[0]['persistence_succeeded']===false,'a failed save is reported as Unable to Save, leaves the file unchanged and is audited');
$calls=0;$corrupting=new BrowserFaceRolloutAdmin($file,function($target,$contents) use(&$calls,$before){if($calls++===0){file_put_contents($target,'{"version":"browser-face-rollout-v1","enabled":true,"scopes":[]}');return;}file_put_contents($target,$contents);});
$r=$corrupting->apply(['action'=>'set','admin_id'=>7,'database'=>$T,'matching'=>$M_NOT,'allowed_branches'=>[4],'valid_batches'=>[4=>[10]],'targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>false,'audit'=>$audit]);
check(!$r['saved'] && $r['code']==='VERIFY_FAILED' && $sha()===$before,'a saved file that does not match the request is detected and the previous settings are restored');
$missing=new BrowserFaceRolloutAdmin($dir.'/nope.json');$r=$missing->apply(['action'=>'stop_all','admin_id'=>7,'database'=>$T,'audit'=>$audit]);
check(!$r['saved'] && $r['code']==='NOT_WRITABLE' || $r['code']==='CONFIG_UNAVAILABLE','a missing settings file is reported, never created');
file_put_contents($dir.'/bad.json','not json');chmod($dir.'/bad.json',0660);$r=(new BrowserFaceRolloutAdmin($dir.'/bad.json'))->apply(['action'=>'stop_all','admin_id'=>7,'database'=>$T,'audit'=>$audit]);
check(!$r['saved'] && $r['code']==='CONFIG_INVALID' && file_get_contents($dir.'/bad.json')==='not json','a corrupt settings file is reported and left alone');
$noAudit=function(){return false;};$seed($cfg());
$r=$admin->apply(['action'=>'stop','admin_id'=>7,'database'=>$T,'matching'=>$M_NOT,'allowed_branches'=>[4],'valid_batches'=>[4=>[10]],'targets'=>[['branch_id'=>4,'batch_id'=>10]],'audit'=>$noAudit]);
check($r['saved'] && $r['audit_ok']===false && $mode($disk(),4,10)==='DISABLED','if the audit log is unavailable the saved change is flagged for the administrator, not hidden');

// 9. global stop and resume
$seed($cfg());$events=[];$before=$sha();
$r=$request(['action'=>'stop_all']);
check($r['saved'] && $disk()['enabled']===false && $mode($disk(),4,10)==='DISABLED' && $mode($disk(),5,20)==='DISABLED' && in_array('face_rollout_rolled_back',$names(),true),'stop all disables every branch immediately and logs face_rollout_rolled_back');
check($request(['action'=>'stop_all'])['code']==='NO_CHANGE','stopping twice changes nothing');
$events=[];$r=$request(['action'=>'resume_all']);
check($r['saved'] && $disk()['enabled']===true && $mode($disk(),4,10)==='SHADOW' && in_array('face_feature_enabled',$names(),true),'resume restores the previous scoped behaviour');

// 10. the real audit log is private data only
$seed($cfg());@unlink($logFile);
$real=new BrowserFaceRolloutAdmin($file);
$real->apply(['action'=>'set','admin_id'=>9,'database'=>$T,'matching'=>$M_NOT,'allowed_branches'=>[4],'valid_batches'=>[4=>[10]],'targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>false]);
$real->apply(['action'=>'set','admin_id'=>9,'database'=>$T,'matching'=>$M_NOT,'allowed_branches'=>[4],'valid_batches'=>[4=>[10]],'targets'=>[['branch_id'=>4,'batch_id'=>10]],'enrollment'=>true,'scanning'=>true,'automatic'=>true]);
$lines=array_values(array_filter(explode("\n",(string)@file_get_contents($logFile))));
check(count($lines)===2,'the shared private audit log received both administrator events');
$first=json_decode($lines[0],true);$second=json_decode($lines[1],true);
check($first['event']==='face_mode_changed' && $first['actor_id']===9 && $first['previous_mode']==='SHADOW' && $first['new_mode']==='CAPTURE_ONLY' && $first['branch_id']===4 && $first['batch_id']===10 && $first['scope_type']==='BATCH' && isset($first['timestamp'],$first['release'],$first['request_id'],$first['config_sha256']),'the logged event has timestamp, release, administrator, scope, previous/new setting and correlation id');
check($second['event']==='face_rollout_rejected' && $second['error_code']==='MATCHING_NOT_APPROVED','a refused LIVE request reaches the private log with its reason');
check(!preg_match('/embedding|vector|template|token|password|secret|key/i',implode("\n",$lines)),'administrator events contain no biometric, credential or key fields');
// exact reasons shown when LIVE is unavailable
check(BrowserFaceRolloutAdmin::matchingReasons($M_OK,'')===[],'no reasons are shown when matching is approved');
check(BrowserFaceRolloutAdmin::matchingReasons($M_NOT,'')===['No approved matching report is installed on the server yet.'],'a missing matching report is explained');
$evFile=$dir.'/calibration.json';file_put_contents($evFile,json_encode(['approved'=>false,'identities'=>4,'samples'=>20]));
$why=implode(' | ',BrowserFaceRolloutAdmin::matchingReasons($M_NOT,$evFile));
check(strpos($why,'not been approved')!==false && strpos($why,'4 people')!==false && strpos($why,'20 validation samples')!==false && strpos($why,'Independent validation')!==false,'the exact validation shortfall (people, samples, approval) is reported');
// menu: the settings entry is removed for everyone except administrators, using the page's own admin rule
$configSource=file_get_contents(dirname(__DIR__,2).'/pf/inc/config.php');
check(preg_match('/if\(!function_exists\(\'face_settings_menu_filter\'\)\).*?\n\}\n/s',$configSource,$fm)===1,'the menu filter is present in the shared navigation config');
eval($fm[0]);
$tree=[['name'=>'Settings','sub'=>[['name'=>'System Config','url'=>'settings.php','sub'=>[]],['name'=>'Face Attendance','url'=>'face-attendance-settings.php','sub'=>[]]]]];
$names2=fn($t)=>array_column($t[0]['sub'],'name');
check($names2(face_settings_menu_filter($tree,true))===['System Config','Face Attendance'],'administrators keep the Face Attendance menu entry');
check($names2(face_settings_menu_filter($tree,false))===['System Config'],'other users do not see the Face Attendance menu entry');
check(strpos($configSource,"(int)\$_SESSION['position_id']===1")!==false && strpos(file_get_contents(dirname(__DIR__,2).'/pf/face-attendance-settings.php'),"(int)\$_SESSION['position_id'] !== 1")!==false,'the menu and the page apply the same administrator rule');
// last change: a real administrator is named; an id with no account is shown as verification testing, never as an administrator
check(BrowserFaceRolloutAdmin::lastChangeLabel(['by'=>'admin:12'],['fname'=>'Asha','lname'=>'Rao'])==='by Asha Rao (administrator #12)','a real administrator is shown by name');
check(strpos(BrowserFaceRolloutAdmin::lastChangeLabel(['by'=>'admin:999999'],null),'production verification testing')!==false && strpos(BrowserFaceRolloutAdmin::lastChangeLabel(['by'=>'admin:999999'],null),'by administrator')===false,'the verification session is identified and not presented as an administrator');
check(BrowserFaceRolloutAdmin::lastChangeLabel(['by'=>'cli'],null)==='by the system','non-administrator writers are labelled as the system');
echo 'Rollout administration regression complete'.PHP_EOL;
