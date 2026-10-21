<?php
require_once __DIR__.'/BrowserFacePolicy.php';
require_once __DIR__.'/BrowserFaceAudit.php';

/**
 * Administrator-side writer for the EXISTING browser-face rollout file.
 *
 * It decides nothing about access. BrowserFacePolicy::rollout() stays the only reader/enforcer and every
 * change is checked through it twice (before writing, and again from the file on disk); a result that is
 * not what was requested is reverted. It adds no roles, PINs or services, never writes biometric data, and
 * can never turn on LIVE on its own: LIVE needs approved matching readiness (supplied by the caller from the
 * existing BrowserFacePolicy::load()) and then still has to be effective under the policy.
 */
final class BrowserFaceRolloutAdmin
{
    public const VERSION='browser-face-rollout-v1';
    public const LIVE_SCOPE_DAYS=90;
    public const STANDARD_SCOPE_DAYS=1825;
    public const MAX_TARGETS=500;
    /** History copies kept per rollout file; older change copies are removed (the baseline copy is never removed). */
    public const HISTORY_KEEP=200;
    public const ACTIONS=['set','stop','reset','stop_all','resume_all'];

    private string $path;
    /** @var callable|null */
    private $writer;

    public function __construct(string $path, ?callable $writer=null){$this->path=$path;$this->writer=$writer;}

    public function path(): string {return $this->path;}
    private function dir(): string {return dirname($this->path);}

    /** Plain-language message for an outcome code; never contains technical detail. */
    public static function message(string $code): string
    {
        return [
            'SAVED'=>'Changes Saved',
            'NO_CHANGE'=>'No changes were needed',
            'CONFIG_UNAVAILABLE'=>'The face attendance settings are not available.',
            'CONFIG_INVALID'=>'The face attendance settings could not be read.',
            'NOT_WRITABLE'=>'The settings cannot be saved right now.',
            'CONFIG_CHANGED'=>'The settings were changed by someone else. Reload the page and try again.',
            'INVALID_REQUEST'=>'The request was not valid.',
            'BRANCH_NOT_AUTHORIZED'=>'You are not authorized to change that branch.',
            'BATCH_NOT_FOUND'=>'One of the selected batches was not found.',
            'NOTHING_SELECTED'=>'Select at least one branch or batch.',
            'TOO_MANY_TARGETS'=>'Too many items were selected.',
            'SCANNING_REQUIRES_ENROLLMENT'=>'Scanning needs face enrollment to be turned on.',
            'LIVE_REQUIRES_BATCH'=>'Automatic attendance can only be turned on for individual batches.',
            'MATCHING_NOT_APPROVED'=>'Automatic Attendance Not Ready: matching has not been approved yet.',
            'TESTING_BRANCH'=>'Automatic Attendance Not Ready: this branch is still in testing.',
            'NO_ACTIVE_ENROLLMENTS'=>'Automatic Attendance Not Ready: this batch has no active enrollments yet.',
            'LIVE_UNVERIFIED'=>'Automatic Attendance Not Ready: this batch could not be checked.',
            'NOT_EFFECTIVE'=>'The requested setting would not take effect, so nothing was changed.',
            'VERIFY_FAILED'=>'The saved settings did not match the request and were restored.',
            'SAVE_FAILED'=>'Unable to Save',
        ][$code] ?? 'Unable to Save';
    }

    /** enrollment/scanning/automatic toggles -> the existing operating mode. */
    public static function modeFor(bool $enrollment,bool $scanning,bool $automatic): string
    {
        if($automatic){
            if(!$enrollment || !$scanning)throw new InvalidArgumentException('SCANNING_REQUIRES_ENROLLMENT');
            return 'LIVE';
        }
        if($scanning && !$enrollment)throw new InvalidArgumentException('SCANNING_REQUIRES_ENROLLMENT');
        if(!$enrollment)return 'DISABLED';
        return $scanning?'SHADOW':'CAPTURE_ONLY';
    }

    /**
     * Exact, plain-language reasons why matching (and so LIVE) is not approved, from the installed calibration
     * evidence. Display only: approval itself is decided by BrowserFacePolicy::load(). Empty when ready.
     */
    public static function matchingReasons(array $matching,string $calibrationFile): array
    {
        if(!empty($matching['ready']) && !empty($matching['report_sha256']))return [];
        $evidence=$calibrationFile!=='' && is_readable($calibrationFile)?json_decode((string)file_get_contents($calibrationFile),true):null;
        if(!is_array($evidence))return ['No approved matching report is installed on the server yet.'];
        $reasons=[];
        if(($evidence['approved'] ?? false)!==true)$reasons[]='The matching report has not been approved.';
        $people=(int)($evidence['identities'] ?? 0);$samples=(int)($evidence['samples'] ?? 0);
        if($people<20)$reasons[]='Matching has been validated on '.$people.' '.($people===1?'person':'people').'; at least 20 are required.';
        if($samples<100)$reasons[]='Matching has '.$samples.' validation '.($samples===1?'sample':'samples').'; at least 100 are required.';
        if(($evidence['independent_validation'] ?? false)!==true || ($evidence['independent_workflow_validation'] ?? false)!==true)$reasons[]='Independent validation has not been completed.';
        if(!$reasons)$reasons[]='The installed matching report does not pass the server\'s checks (version, signature or thresholds).';
        return $reasons;
    }

    /**
     * Plain-language "who made the last change". $user is the matching account row (fname/lname) or null when
     * no account with that id exists, which is how the 2026-10-13 production verification session (actor
     * 999999) is identified. Display only: the audit log and history copies keep the original actor id.
     */
    public static function lastChangeLabel(array $last,?array $user): string
    {
        $by=(string)($last['by'] ?? '');
        if(!preg_match('/^admin:(\d+)$/D',$by,$m))return 'by the system';
        if($user===null)return 'during production verification testing (account #'.$m[1].' is not a real user; no administrator made this change and the settings were restored)';
        $name=trim((string)($user['fname'] ?? '').' '.(string)($user['lname'] ?? ''));
        return 'by '.($name!==''?$name.' (administrator #'.$m[1].')':'administrator #'.$m[1]);
    }

    /** Pure readiness rule used for display and request validation. '' means LIVE may be requested. */
    public static function liveBlocker(array $matching,bool $testingBranch,int $activeEnrollments): string
    {
        if(empty($matching['ready']) || empty($matching['report_sha256']))return 'MATCHING_NOT_APPROVED';
        if($testingBranch)return 'TESTING_BRANCH';
        if($activeEnrollments<1)return 'NO_ACTIVE_ENROLLMENTS';
        return '';
    }

    public function read(): array
    {
        if(!is_file($this->path) || !is_readable($this->path))return ['ok'=>false,'error'=>'CONFIG_UNAVAILABLE'];
        $raw=(string)file_get_contents($this->path);
        $config=json_decode($raw,true);
        if(!is_array($config) || ($config['version'] ?? '')!==self::VERSION)return ['ok'=>false,'error'=>'CONFIG_INVALID'];
        $config['scopes']=array_values(is_array($config['scopes'] ?? null)?$config['scopes']:[]);
        return ['ok'=>true,'config'=>$config,'raw'=>$raw,'sha'=>hash('sha256',$raw)];
    }

    public function writable(): bool
    {
        $root=realpath(dirname(__DIR__,2));$dir=realpath($this->dir());
        if(!$dir || !$root || strpos($dir.DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR)===0)return false;
        return is_dir($dir) && is_writable($dir);
    }

    private static function sameScope($scope,string $database,int $branch,int $batch): bool
    {
        return is_array($scope) && ($scope['database'] ?? '')===$database && (int)($scope['branch_id'] ?? -1)===$branch && (int)($scope['batch_id'] ?? -1)===$batch;
    }

    /** First matching scope, as the policy sees it (the CLI emergency stop prepends). */
    public static function scopeMode(array $config,string $database,int $branch,int $batch): ?string
    {
        foreach($config['scopes'] ?? [] as $scope)if(self::sameScope($scope,$database,$branch,$batch))return (string)($scope['mode'] ?? '');
        return null;
    }

    public static function effectiveMode(array $config,string $database,int $branch,int $batch,array $matching): string
    {
        return (string)(BrowserFacePolicy::rollout($database,$branch,$batch,$matching,$config)['operating_mode'] ?? 'DISABLED');
    }

    private static function eventFor(string $previous,string $new): string
    {
        if($previous==='LIVE' && $new!=='LIVE')return 'face_rollout_rolled_back';
        if($new==='DISABLED' && $previous!=='DISABLED')return 'face_feature_disabled';
        if($previous==='DISABLED' && $new!=='DISABLED')return 'face_feature_enabled';
        return 'face_mode_changed';
    }

    private function lock()
    {
        $handle=@fopen($this->dir().'/.rollout.lock','c');
        if(!$handle)return null;
        @chmod($this->dir().'/.rollout.lock',0660);
        $end=microtime(true)+5;
        while(!flock($handle,LOCK_EX|LOCK_NB)){if(microtime(true)>$end){fclose($handle);return null;}usleep(50000);}
        return $handle;
    }

    private function atomicWrite(string $target,string $contents): void
    {
        if($this->writer && $target===$this->path){($this->writer)($target,$contents);return;}
        $tmp=dirname($target).'/.rollout.'.bin2hex(random_bytes(6)).'.tmp';
        $old=umask(0007);
        try{
            $handle=@fopen($tmp,'xb');
            if(!$handle)throw new RuntimeException('Cannot create settings temp file');
            $ok=fwrite($handle,$contents)===strlen($contents) && fflush($handle);
            if($ok && function_exists('fsync'))@fsync($handle);
            fclose($handle);
            if(!$ok)throw new RuntimeException('Cannot write settings temp file');
            @chmod($tmp,0660);
            if(!@rename($tmp,$target))throw new RuntimeException('Cannot replace settings file');
        }finally{umask($old);if(is_file($tmp))@unlink($tmp);}
    }

    private function saveHistory(string $json,array $meta): bool
    {
        try{
            $dir=$this->dir().'/history';
            if(!is_dir($dir)){$old=umask(0007);@mkdir($dir,0770,true);umask($old);}
            if(!is_dir($dir) || !is_writable($dir))return false;
            $name=$dir.'/rollout-'.gmdate('Ymd\THis\Z').'-'.preg_replace('/[^a-f0-9]/','',(string)$meta['request_id']).'.json';
            $this->atomicWrite($name,json_encode(['meta'=>$meta,'config'=>json_decode($json,true)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
            $this->pruneHistory($dir);
            return true;
        }catch(Throwable $e){return false;}
    }

    /** Retention: keep the newest HISTORY_KEEP change copies. Failure to prune never fails a save. */
    private function pruneHistory(string $dir): void
    {
        try{
            $files=glob($dir.'/rollout-[0-9]*T[0-9]*Z-*.json') ?: [];
            if(count($files)<=self::HISTORY_KEEP)return;
            rsort($files,SORT_STRING);
            foreach(array_slice($files,self::HISTORY_KEEP) as $old)@unlink($old);
        }catch(Throwable $e){}
    }

    /**
     * Apply one administrator change.
     *
     * $req: action, admin_id, database, request_id?, expected_sha?, targets[[branch_id,batch_id]] (batch_id 0 = whole branch),
     *       enrollment/scanning/automatic (for 'set'), matching (BrowserFacePolicy::load()), allowed_branches[int],
     *       valid_batches[branch=>[int]], live_blockers[branch][batch]=>code|'' (for LIVE), audit? callable(event,meta):bool
     * Returns saved/changed/code/message/changes[]/audit_ok/history_ok. Nothing is written unless saved is true.
     */
    public function apply(array $req): array
    {
        $started=microtime(true);
        $requestId=preg_match('/^[a-f0-9]{8,32}$/D',(string)($req['request_id'] ?? ''))?$req['request_id']:bin2hex(random_bytes(8));
        $admin=(int)($req['admin_id'] ?? 0);$database=(string)($req['database'] ?? '');$action=(string)($req['action'] ?? '');
        $matching=is_array($req['matching'] ?? null)?$req['matching']:[];
        $audit=is_callable($req['audit'] ?? null)?$req['audit']:fn(string $event,array $meta):bool=>BrowserFaceAudit::write($event,$meta);
        $base=['request_id'=>$requestId,'actor_id'=>$admin,'tenant'=>preg_match('/^[A-Za-z0-9_]{1,60}$/D',$database)?$database:'invalid'];
        $reject=function(string $code,array $extra=[]) use($audit,$base,$started,$action):array{
            $audit('face_rollout_rejected',$base+['operation'=>preg_match('/^[a-z_]{1,20}$/D',$action)?$action:'invalid','result'=>'rejected','error_code'=>$code,'persistence_succeeded'=>false,'duration_ms'=>round((microtime(true)-$started)*1000,2)]+$extra);
            return ['saved'=>false,'changed'=>false,'code'=>$code,'message'=>self::message($code),'changes'=>[],'audit_ok'=>true,'history_ok'=>true,'request_id'=>$base['request_id']];
        };
        if($admin<1 || !preg_match('/^[A-Za-z0-9_]{1,60}$/D',$database) || !in_array($action,self::ACTIONS,true))return $reject('INVALID_REQUEST');
        $global=in_array($action,['stop_all','resume_all'],true);
        $allowed=array_map('intval',(array)($req['allowed_branches'] ?? []));
        $valid=[];foreach((array)($req['valid_batches'] ?? []) as $b=>$list)$valid[(int)$b]=array_map('intval',(array)$list);

        // Targets: validated before anything is locked or written. Any invalid target rejects the whole request.
        $targets=[];
        if(!$global){
            $seen=[];
            foreach((array)($req['targets'] ?? []) as $t){
                $branch=(int)($t['branch_id'] ?? 0);$batch=(int)($t['batch_id'] ?? 0);
                if($branch<1 || $batch<0)return $reject('INVALID_REQUEST');
                if(!in_array($branch,$allowed,true))return $reject('BRANCH_NOT_AUTHORIZED',['branch_id'=>$branch]);
                if($batch>0 && !in_array($batch,$valid[$branch] ?? [],true))return $reject('BATCH_NOT_FOUND',['branch_id'=>$branch,'batch_id'=>$batch]);
                if(isset($seen[$branch.':'.$batch]))continue;$seen[$branch.':'.$batch]=true;$targets[]=['branch_id'=>$branch,'batch_id'=>$batch];
            }
            if(!$targets)return $reject('NOTHING_SELECTED');
            if(count($targets)>self::MAX_TARGETS)return $reject('TOO_MANY_TARGETS');
        }
        $mode=null;
        if($action==='set'){
            try{$mode=self::modeFor((bool)($req['enrollment'] ?? false),(bool)($req['scanning'] ?? false),(bool)($req['automatic'] ?? false));}
            catch(InvalidArgumentException $e){return $reject($e->getMessage());}
            if($mode==='LIVE'){
                foreach($targets as $t){
                    if($t['batch_id']<1)return $reject('LIVE_REQUIRES_BATCH',$t);
                    $blocker=empty($matching['ready']) || empty($matching['report_sha256'])?'MATCHING_NOT_APPROVED':(string)($req['live_blockers'][$t['branch_id']][$t['batch_id']] ?? 'LIVE_UNVERIFIED');
                    if($blocker!=='')return $reject($blocker,$t);
                }
            }
        }elseif($action==='stop')$mode='DISABLED';

        if(!$this->writable())return $reject('NOT_WRITABLE');
        $lock=$this->lock();
        if(!$lock)return $reject('NOT_WRITABLE');
        try{
            $current=$this->read();
            if(!$current['ok'])return $reject($current['error']);
            $expected=(string)($req['expected_sha'] ?? '');
            if($expected!=='' && !hash_equals($current['sha'],$expected))return $reject('CONFIG_CHANGED');
            $old=$current['config'];$new=$old;
            // A branch-wide change is evaluated on a batch of that branch that has no scope of its own.
            $changes=[];$rep=function(array $config,int $branch,int $batch) use($valid,$database):int{
                if($batch>0)return $batch;
                foreach($valid[$branch] ?? [] as $candidate)if(!self::batchHasScope($config,$database,$branch,$candidate))return $candidate;
                return 0;
            };
            if($global){
                $enable=$action==='resume_all';
                if(($old['enabled'] ?? true)===$enable)return ['saved'=>true,'changed'=>false,'code'=>'NO_CHANGE','message'=>self::message('NO_CHANGE'),'changes'=>[],'audit_ok'=>true,'history_ok'=>true,'request_id'=>$requestId,'sha'=>$current['sha']];
                $new['enabled']=$enable;
                $changes[]=['branch_id'=>0,'batch_id'=>0,'scope_type'=>'GLOBAL','previous'=>$enable?'DISABLED':'ENABLED','new'=>$enable?'ENABLED':'DISABLED','result'=>'applied'];
            }else{
                foreach($targets as $t){
                    $branch=$t['branch_id'];$batch=$t['batch_id'];
                    $prevScope=self::scopeMode($old,$database,$branch,$batch);
                    $prevEffective=self::effectiveMode($old,$database,$branch,$rep($old,$branch,$batch),$matching);
                    $new['scopes']=array_values(array_filter($new['scopes'],fn($s)=>!self::sameScope($s,$database,$branch,$batch)));
                    if($action!=='reset'){
                        $scope=['database'=>$database,'branch_id'=>$branch,'batch_id'=>$batch,'mode'=>$mode,'approved_by'=>'admin:'.$admin,'approved_at'=>gmdate('c'),'change_id'=>$requestId];
                        if($mode!=='DISABLED')$scope['expires_at']=gmdate('c',time()+86400*($mode==='LIVE'?self::LIVE_SCOPE_DAYS:self::STANDARD_SCOPE_DAYS));
                        if($mode==='LIVE')$scope['matching_report_sha256']=(string)$matching['report_sha256'];
                        array_unshift($new['scopes'],$scope);
                    }
                    $newScope=self::scopeMode($new,$database,$branch,$batch);
                    $repBatch=$rep($new,$branch,$batch);
                    $newEffective=self::effectiveMode($new,$database,$branch,$repBatch,$matching);
                    // Without any batch to evaluate, only DISABLED (which does not depend on a batch) can be verified.
                    $expect=($action==='reset' || ($batch===0 && $repBatch===0 && $mode!=='DISABLED'))?null:$mode;
                    // A change that the policy would not honour (for example LIVE without readiness) is never written.
                    if($expect!==null && !self::acceptable($expect,$newEffective))return $reject($mode==='LIVE'?'MATCHING_NOT_APPROVED':'NOT_EFFECTIVE',$t);
                    $changes[]=['branch_id'=>$branch,'batch_id'=>$batch,'scope_type'=>$batch>0?'BATCH':'BRANCH','previous'=>$prevEffective,'new'=>$newEffective,'result'=>($prevScope===$newScope && $prevEffective===$newEffective)?'unchanged':'applied','expect'=>$expect];
                }
                if(!array_filter($changes,fn($c)=>$c['result']==='applied'))return ['saved'=>true,'changed'=>false,'code'=>'NO_CHANGE','message'=>self::message('NO_CHANGE'),'changes'=>$changes,'audit_ok'=>true,'history_ok'=>true,'request_id'=>$requestId,'sha'=>$current['sha']];
            }
            $new['last_change']=['at'=>gmdate('c'),'by'=>'admin:'.$admin,'request_id'=>$requestId,'action'=>$action];
            $json=json_encode($new,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            try{$this->atomicWrite($this->path,$json);}
            catch(Throwable $e){return $reject('SAVE_FAILED',['persistence_succeeded'=>false]);}
            // Re-read from disk and ask the policy again: only a verified result is reported as saved.
            $disk=$this->read();$verified=$disk['ok'] && hash_equals(hash('sha256',$json),$disk['sha']);
            if($verified && $global)$verified=$action==='stop_all'?self::effectiveMode($disk['config'],$database,1,0,$matching)==='DISABLED':(($disk['config']['enabled'] ?? true)===true);
            if($verified && !$global)foreach($changes as $c){
                if($c['result']!=='applied' || ($c['expect'] ?? null)===null)continue;
                $effective=self::effectiveMode($disk['config'],$database,$c['branch_id'],$rep($disk['config'],$c['branch_id'],$c['batch_id']),$matching);
                if(!self::acceptable($c['expect'],$effective)){$verified=false;break;}
            }
            if(!$verified){
                try{$this->atomicWrite($this->path,$current['raw']);}catch(Throwable $e){}
                return $reject('VERIFY_FAILED',['persistence_succeeded'=>false]);
            }
            $newSha=hash('sha256',$json);
        }finally{flock($lock,LOCK_UN);fclose($lock);}

        $historyOk=$this->saveHistory($json,['at'=>gmdate('c'),'by'=>'admin:'.$admin,'request_id'=>$requestId,'action'=>$action,'previous_sha'=>$current['sha'],'new_sha'=>$newSha,'changes'=>array_map(fn($c)=>array_diff_key($c,['expect'=>1]),$changes)]);
        $auditOk=true;
        foreach($changes as $c){
            if($c['result']!=='applied')continue;
            $event=$c['scope_type']==='GLOBAL'?($action==='stop_all'?'face_rollout_rolled_back':'face_feature_enabled'):self::eventFor((string)$c['previous'],(string)$c['new']);
            $auditOk=$audit($event,$base+['operation'=>$action,'branch_id'=>(int)$c['branch_id'],'batch_id'=>(int)$c['batch_id'],'scope_type'=>$c['scope_type'],'previous_mode'=>(string)$c['previous'],'new_mode'=>(string)$c['new'],'result'=>'saved','persistence_succeeded'=>true,'change_count'=>count($changes),'config_sha256'=>$newSha,'duration_ms'=>round((microtime(true)-$started)*1000,2)]) && $auditOk;
        }
        return ['saved'=>true,'changed'=>true,'code'=>'SAVED','message'=>self::message('SAVED'),'changes'=>array_map(fn($c)=>array_diff_key($c,['expect'=>1]),$changes),'audit_ok'=>$auditOk,'history_ok'=>$historyOk,'request_id'=>$requestId,'sha'=>$newSha];
    }

    private static function batchHasScope(array $config,string $database,int $branch,int $batch): bool
    {
        foreach($config['scopes'] ?? [] as $scope)if(self::sameScope($scope,$database,$branch,$batch))return true;
        return false;
    }

    /** The Testing branch reports TEST where an ordinary one reports SHADOW; both are the non-writing mode. */
    private static function acceptable(?string $expected,string $effective): bool
    {
        return $effective===$expected || ($expected==='SHADOW' && $effective==='TEST');
    }
}
