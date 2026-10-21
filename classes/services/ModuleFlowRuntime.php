<?php
require_once dirname(__DIR__).'/session.class.php';
require_once __DIR__.'/ModuleFollowupService.php';

final class ModuleFlowRuntime
{
    private static ?mysqli $db=null;
    private static bool $enabled=false;
    private static bool $writing=false;
    private static bool $finished=false;
    private static int $buffer=0;
    private static string $tenant='';
    private static array $locks=[];
    private static array $centralOnly=[];
    private static ?string $operationKey=null;
    public static array $context=[];

    public static function active(): bool { return self::$enabled; }
    public static function owns(mysqli $db): bool { return self::$enabled && self::$db===$db; }
    public static function connectionFor($name): ?mysqli
    {
        if (!self::$enabled || !self::$db) return null;
        if (!in_array($name,[self::$tenant,'ccc_central',ModuleFlowConnection::$centralSchema],true)) throw new DomainException('A different tenant database is not authorized');
        return self::$db;
    }
    public static function db(): mysqli { if (!self::$db) throw new LogicException('No module transaction'); return self::$db; }
    public static function qualify(string $sql): string
    {
        // These catalog names have no tenant-domain meaning. Module/action are
        // intentionally excluded because tenants also have syllabus modules.
        $tables=implode('|',array_map(fn($t)=>preg_quote($t,'~'),array_unique(array_merge(self::$centralOnly,['user','user_bid','user_device','user_social','user_permission','user_report_permission','client_db','client_module','position_template','employee_position','employee_department','notify_user','fcm_cron_data','fcm_log','client_error_logs','system_cron_log','forms_webhook_log','system_sms_credential','sms_vendor_client','online_inquiry','payment_link','sms_ready','email_ready','request']))));
        $pattern="~'(?:\\\\.|''|[^'\\\\])*'(*SKIP)(*F)|\"(?:\\\\.|\"\"|[^\"\\\\])*\"(*SKIP)(*F)|\\b(FROM|JOIN|UPDATE|INTO|DELETE FROM)\\s+`?($tables)`?(?![A-Za-z0-9_]|\\s*\\.)~i";
        return preg_replace_callback($pattern,fn($m)=>$m[1].' `'.ModuleFlowConnection::$centralSchema.'`.`'.$m[2].'`',$sql);
    }
    public static function response(int $error,string $message,string $code='',array $data=[]): array
    { return ['error'=>$error,'error_msg'=>$code,'data'=>$data,'message'=>$message]; }
    public static function sendFile(string $path): void
    {
        if(!is_file($path))throw new DomainException('Attachment is missing');
        self::$finished=true;while(ob_get_level()>self::$buffer)ob_end_clean();
        header('Content-Type: '.(new finfo(FILEINFO_MIME_TYPE))->file($path));header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Content-Disposition: attachment; filename="attachment.'.pathinfo($path,PATHINFO_EXTENSION).'"');
        readfile($path);exit;
    }
    public static function lock(string $key): void
    {
        $physical=self::db()->query('SELECT DATABASE()')->fetch_row()[0];
        $name='cccflow_'.substr(hash('sha256',$physical.':'.$key),0,48);
        $stmt=self::db()->prepare('SELECT GET_LOCK(?,10)');$stmt->bind_param('s',$name);$stmt->execute();$ok=(int)$stmt->get_result()->fetch_row()[0];$stmt->close();
        if ($ok!==1) throw new RuntimeException('Operation is busy. Retry with the same operation key.');
        self::$locks[]=$name;
    }
    public static function start(string $route,string $action,array &$request): void
    {
        if($route==='batch' && $action==='selectWeb') return; // Existing public schedule projection authorizes its own columns.
        self::$buffer=ob_get_level();ob_start();
        set_exception_handler(function(Throwable $e){ self::fail($e); });
        register_shutdown_function(function(){
            if (self::$finished) return;
            $last=error_get_last();
            if ($last && in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) self::fail(new RuntimeException('Unexpected module failure'));
            else self::finish();
        });
        $session=new session();$dbname=$session->check_session($request);
        if (!$dbname) {self::emit(self::response(2,'Please sign in again','AUTH_REQUIRED'));exit;}
        self::$context=$GLOBALS['ccc_authenticated_context'];self::$tenant=$dbname;
        $memberSelf=self::$context['type']==='M' && $route==='self' && $action==='selectSelf';
        $memberCredential=self::$context['type']==='M' && $route==='user' && in_array($action,['changePassword','changeMpin','updateFCM','logout'],true);
        if (self::$context['type']==='M' && !$memberSelf && !$memberCredential) {self::emit(self::response(3,'Use the member self-service API','STAFF_ACCESS_REQUIRED'));exit;}
        if($route==='self'&&!$memberSelf)throw new DomainException('A member session is required');
        $isUserPermissionAction = $route==='user' && in_array($action,['selectPermission','selectModulePermission','selectReportPermission','updatePermission','updateModulePermission','updateReportPermission'],true);
        if (!$isUserPermissionAction && isset($request['bid']) && (int)$request['bid']>0 && (int)$request['bid']!==(int)get_bid()) {
            if (in_array((int)$request['bid'], self::$context['allowed_bids'] ?? [], true)) {
                $GLOBALS['ccc_authenticated_context']['bid'] = (int)$request['bid'];
                $_SESSION['bid'] = (int)$request['bid'];
            } else {
                throw new DomainException('The request branch does not match the authenticated branch');
            }
        }
        if($route==='employee' && $action==='updateparentEmployee' && is_scalar($request['filter']??null)){ $request['employee_id']=(int)$request['filter']; unset($request['filter']); }
        $request['user']=(int)get_uid();
        if(!$isUserPermissionAction) $request['bid']=(int)get_bid();
        $request['uid']=(int)get_uid();$request['client_id']=(int)self::$context['client_id'];
        $modules=['employee'=>'Employee','invoice'=>'Invoice','member'=>'Member','inquiry'=>'Inquiry','followup'=>'Follow up','batch'=>'Batch','attendance'=>'Attendance','contact'=>'Contact','payment'=>'Payment','batchSession'=>'Category','attRequest'=>'Employee Attendance'];
        self::$writing=!preg_match('/^(select|get|check|count|find|search|has|total|exist)/i',$action);
        if($route==='batchSession' && $action==='generateEndDate')self::$writing=false;
        $level=self::$writing?2:1;
        if (in_array($action,['park','delete','undoBatchCancel'],true)) $level=3;
        $module=$modules[$route]??null;
        if($route==='invoice' && in_array($action,['update_payment','insertMultiplePayment'],true))$module='Payment';
        if($route==='batchSession' && $action==='generateEndDate')$module='Batch';
        // Roster is an attendance capability; it does not grant contact browsing.
        if ($route==='member' && in_array($action,['selectBatchMember','selectBatchMemberApp'],true)) $module='Attendance';
        if ($route==='attRequest') $module=null; // Per-person hierarchy checks occur in that controller.
        if ($module && !$session->access(get_uid(),(new dbconnection())->module($module),$level)) {
            self::emit(self::response(3,'You do not have permission for this operation','access denied'));exit;
        }
        // Qualify central-only tables automatically from trusted schema metadata.
        // Overlapping syllabus module/action names retain explicit qualification.
        $central=(new dbconnection())->connect('ccc_central');
        $centralTables=array_column($central->query('SHOW TABLES')->fetch_all(MYSQLI_NUM),0);$central->close();
        self::$db=(new dbconnection())->connect($dbname);
        $tenantTables=array_column(self::$db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM),0);
        self::$centralOnly=array_values(array_diff($centralTables,$tenantTables));
        self::$enabled=true;self::$db->set_charset('utf8mb4');
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
        if($memberSelf){require_once __DIR__.'/ModuleMemberSelfService.php';self::emit(ModuleMemberSelfService::read(),true);exit;}
        if($route==='user' && $action==='selectReportPermission' && isset($request['module_id'])){if(!preg_match('/^\\d+(?:,\\d+)*$/D',(string)$request['module_id']))throw new InvalidArgumentException('Choose valid report modules');$request['module_ids']=array_map('intval',explode(',',$request['module_id']));unset($request['module_id']);}
        self::validateRequest($request);
        foreach(['mobile','country_code'] as $phoneField){
            if(isset($request[$phoneField]))$request[$phoneField]=preg_replace('/[+\\s()-]+/','',(string)$request[$phoneField]);
            if(isset($request['inquiry'][$phoneField]))$request['inquiry'][$phoneField]=preg_replace('/[+\\s()-]+/','',(string)$request['inquiry'][$phoneField]);
        }
        $isMobileCheck = in_array($action, ['checkDuplicateMobile', 'validateMobile', 'checkMobile'], true);
        if(!$isMobileCheck && isset($request['mobile'])&&$request['mobile']!==''&&!preg_match('/^\\d{8,15}$/D',$request['mobile']))throw new InvalidArgumentException('Invalid mobile');
        if(!$isMobileCheck && isset($request['country_code'])&&$request['country_code']!==''&&!preg_match('/^\\d{1,4}$/D',$request['country_code']))throw new InvalidArgumentException('Invalid country code');
        if(isset($request['inquiry']['mobile'])&&!preg_match('/^\\d{8,15}$/D',$request['inquiry']['mobile']))throw new InvalidArgumentException('Invalid inquiry mobile');
        if(isset($request['inquiry']['country_code'])&&!preg_match('/^\\d{1,4}$/D',$request['inquiry']['country_code']))throw new InvalidArgumentException('Invalid inquiry country code');
        if (self::$writing) self::$db->begin_transaction();
        self::scope($route==='invoice'&&$action==='update_payment'?'payment':$route,$action==='update_payment'?'update':$action,$request);
        require_once __DIR__.'/ModuleObjectPolicy.php';ModuleObjectPolicy::guard($route,$action,$request,self::$writing);
        if($route==='attRequest'){require_once __DIR__.'/ModuleStaffRequestPolicy.php';ModuleStaffRequestPolicy::guard($action,$request);}
        if($route==='invoice' && $action==='insert') {
            require_once __DIR__.'/ModuleInvoicePolicy.php';
            self::beginOperation($route.':'.$action,$request);
            ModuleInvoicePolicy::prepare($request);
        }
        if($route==='payment' && self::$writing){
            self::lock('billing-numbering');if($action==='insert')self::beginOperation('payment:insert',$request);
            require_once __DIR__.'/ModulePaymentPolicy.php';ModulePaymentPolicy::prepare($action,$request);
        }
        if($route==='invoice' && in_array($action,['update_payment','insertMultiplePayment'],true)){self::lock('billing-numbering');require_once __DIR__.'/ModulePaymentPolicy.php';if($action==='update_payment')ModulePaymentPolicy::prepare('update',$request);else{self::beginOperation('invoice:payments',$request);foreach($request['i']??[] as &$payment){$payment['contact_id']=(int)($request['contact_id']??0);ModulePaymentPolicy::prepare('insert',$payment);}unset($payment);}}
        require_once __DIR__.'/AttendanceLedger.php';
        if($route==='batch' && in_array($action,['insert','update'],true)){require_once __DIR__.'/ModuleBatchPolicy.php';ModuleBatchPolicy::validate($action,$request);}
        if($route==='attendance' && $action==='batchLog'){require_once __DIR__.'/ModuleBatchPolicy.php';ModuleBatchPolicy::cancel($request);}
    }
    private static function validateRequest(array $r): void
    {
        foreach(['inquiry'=>['employee_id','category','package','offer'],'invoice_item'=>[]] as $section=>$keys){if(!is_array($r[$section]??null))continue;foreach($keys as $key)if(isset($r[$section][$key]))foreach((array)$r[$section][$key] as $value)if(!ctype_digit((string)$value))throw new InvalidArgumentException('Invalid '.$key);}
        foreach (['limit','offset'] as $k) if(isset($r[$k]) && $r[$k]!=='' && (!ctype_digit((string)$r[$k]) || (int)$r[$k]>100000)) throw new InvalidArgumentException('Invalid '.$k);
        foreach (['table','from','groupby'] as $k) if(!empty($r[$k]) && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\s*,\s*[A-Za-z_][A-Za-z0-9_]*)*$/D',(string)$r[$k])) throw new InvalidArgumentException('Invalid '.$k);
        foreach ($r as $key=>$value) {
            if ($key==='session_id') continue;
            if ((preg_match('/(?:^|_)id$/',$key) || in_array($key,['bid','user','s_id'],true)) && is_scalar($value) && $value!=='' && !ctype_digit((string)$value)) throw new InvalidArgumentException('Invalid identifier: '.$key);
        }
        foreach(['filter','d','orfilter','order'] as $key) if(isset($r[$key]) && $r[$key]!=='' && !is_array($r[$key])) throw new InvalidArgumentException('Invalid '.$key);
    }
    private static function scope(string $route,string $action,array &$r): void
    {
        $db=self::db();$bid=(int)get_bid();
        $tables=['batch'=>'batch','member'=>'member','inquiry'=>'inquiry','attendance'=>'attendance','contact'=>'contact','invoice'=>'invoice','employee'=>'employee','followup'=>'followup','payment'=>'payment'];
        $table=$tables[$route]??null;
        if ($route==='invoice' && in_array($action,['updateInvoiceItem','selectInvoiceItem'],true)) $table='invoice_item';
        if ($route==='invoice' && in_array($action,['updateGraduationCertificate','insertGraduationCertificate'],true)) $table='graduation_certificate';
        if ($route==='invoice' && in_array($action,['insertMessageInfo','updateMessageInfo'],true)) $table='message_certificate';
        if(!self::$writing){
            if($table && $table!=='employee')$r['filter'][]=['bid','=',$bid];
            if($table==='employee' && in_array($action,['select','select_teacher'],true)){ $r['filter'][]=['bid','=',$bid]; }
            return;
        }
        $filters=$r[in_array($action,['park','delete'],true)?'d':'filter']??null;
        $generic=in_array($action,['update','park','delete','updateInvoiceItem'],true);
        if(self::$writing && $generic && (!$filters || !is_array($filters))) throw new InvalidArgumentException('An explicit record selection is required');
        if(self::$writing && !empty($r['orfilter'])) throw new InvalidArgumentException('OR filters cannot broaden a mutation');
        if(!$table || !$filters) return;
        if(self::$writing && $generic) {
            $selected=false;foreach($filters as $f) if(in_array($f[0]??'',['id','contact_id'],true) && in_array(strtoupper($f[1]??''),['=','IN'],true)) $selected=true;
            if(!$selected) throw new InvalidArgumentException('Select records by id or contact_id');
        }
        $where=(new querybuilder())->where($filters,'SELECT * FROM `'.$table.'`');
        $rows=$db->query($where)->fetch_all(MYSQLI_ASSOC);
        foreach($rows as $row){
            $allowed=true;
            if($table==='employee') $allowed=(bool)$db->query('SELECT id FROM employee_bid WHERE employee_id='.(int)$row['id'].' AND bid='.$bid)->num_rows;
            elseif($table==='invoice_item' || $table==='graduation_certificate' || $table==='message_certificate') $allowed=(bool)$db->query('SELECT id FROM invoice WHERE id='.(int)$row['invoice_id'].' AND bid='.$bid)->num_rows;
            elseif(array_key_exists('bid',$row)) $allowed=(int)$row['bid']===$bid;
            if(!$allowed) throw new DomainException('The selected record belongs to another branch');
        }
    }
    private static function fail(Throwable $e): void
    {
        error_log('Module flow: '.get_class($e).' '.$e->getMessage());
        $expected=$e instanceof InvalidArgumentException || $e instanceof DomainException;
        self::emit(self::response($e instanceof DomainException?3:1,$expected?$e->getMessage():'The operation was not saved. Please retry.',$expected?'VALIDATION_FAILED':'OPERATION_FAILED'),false);
    }
    private static function beginOperation(string $action,array $r): void
    {
        foreach(['session_id','uid','client_id','idempotency_key','operation_id'] as $k)unset($r[$k]);
        $hash=hash('sha256',json_encode($r,JSON_PRESERVE_ZERO_FRACTION));
        $key=(string)($_POST['idempotency_key']??$_POST['operation_id']??('legacy:'.$hash));
        if(!preg_match('/^[A-Za-z0-9:_-]{8,128}$/D',$key))throw new InvalidArgumentException('Invalid operation key');
        $key=hash('sha256',get_uid().':'.get_bid().':'.$action.':'.$key);
        self::lock('operation:'.$key);
        $stmt=self::db()->prepare('SELECT payload_hash,response FROM module_flow_operation WHERE operation_key=? FOR UPDATE');$stmt->bind_param('s',$key);$stmt->execute();$existing=$stmt->get_result()->fetch_assoc();$stmt->close();
        if($existing){
            if(!hash_equals($existing['payload_hash'],$hash))throw new InvalidArgumentException('An operation key cannot be reused for changed data');
            if($existing['response']){self::emit(json_decode($existing['response'],true),true);exit;}
        }
        $uid=(int)get_uid();$bid=(int)get_bid();
        $stmt=self::db()->prepare('INSERT INTO module_flow_operation(operation_key,actor_id,branch_id,action,payload_hash) VALUES(?,?,?,?,?)');$stmt->bind_param('siiss',$key,$uid,$bid,$action,$hash);$stmt->execute();$stmt->close();self::$operationKey=$key;
    }
    public static function finish(): void
    {
        $body='';while(ob_get_level()>self::$buffer)$body=ob_get_clean().$body;
        $r=json_decode(trim($body),true);
        if(!is_array($r) || !array_key_exists('error',$r)) {self::fail(new RuntimeException('Invalid module response'));return;}
        if((int)$r['error']===0 && class_exists('ModulePaymentPolicy',false)){try{ModulePaymentPolicy::finalize();}catch(Throwable $e){self::fail($e);return;}}
        self::emit($r,(int)$r['error']===0);
    }
    private static function emit(array $r,bool $success=false): void
    {
        if(self::$finished)return;self::$finished=true;
        while(ob_get_level()>self::$buffer)ob_end_clean();
        try {
            if($success && self::$operationKey){$body=json_encode($r,JSON_INVALID_UTF8_SUBSTITUTE);$stmt=self::$db->prepare('UPDATE module_flow_operation SET response=? WHERE operation_key=?');$stmt->bind_param('ss',$body,self::$operationKey);$stmt->execute();$stmt->close();}
            if(self::$writing && self::$db) {if($success)self::$db->commit();else self::$db->rollback();}
        }catch(Throwable $e){if(self::$db)self::$db->rollback();$r=self::response(1,'The operation could not be committed','COMMIT_FAILED');error_log('Module commit failed: '.$e->getMessage());}
        foreach(self::$locks as $lock){$stmt=self::$db->prepare('SELECT RELEASE_LOCK(?)');$stmt->bind_param('s',$lock);$stmt->execute();$stmt->close();}
        self::$enabled=false;
        $r+=['data'=>[],'error_msg'=>'','message'=>''];
        if(!headers_sent())header('Content-Type: application/json; charset=utf-8');
        echo json_encode($r,JSON_INVALID_UTF8_SUBSTITUTE);
        if(self::$db)self::$db->close();
    }
}
