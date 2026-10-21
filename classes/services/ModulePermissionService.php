<?php
require_once __DIR__.'/ModuleRolePolicy.php';
final class ModulePermissionService
{
    public static function handle(string $action,array $r): ?array
    {
        if(!ModuleFlowRuntime::active())return null;
        $db=ModuleFlowRuntime::db();$auth=ModuleFlowRuntime::$context;$uid=(int)get_uid();$cid=(int)$auth['client_id'];
        if(in_array($action,['updateFCM','logout'],true)){
            $token=(string)get_token();$value=(string)($r['fcm_token']??'');
            if($action==='updateFCM'){$stmt=$db->prepare('UPDATE user_device SET fcm_token=? WHERE user_id=? AND access_token=? AND park=0 AND logout=0');$stmt->bind_param('sis',$value,$uid,$token);}
            else{$stmt=$db->prepare("UPDATE user_device SET logout=1,fcm_token='' WHERE user_id=? AND access_token=?");$stmt->bind_param('is',$uid,$token);}
            $stmt->execute();$stmt->close();if($action==='logout'&&session_status()===PHP_SESSION_ACTIVE){$_SESSION=[];session_destroy();}
            return ModuleFlowRuntime::response(0,$action==='logout'?'Signed out':'Device settings saved');
        }
        if($action==='setFirstLogin'){$db->query('UPDATE user SET is_first_login=0 WHERE id='.$uid.' AND client_id='.$cid);return ModuleFlowRuntime::response(0,'Login setup acknowledged');}
        if($action==='updateClientProgress'){
            if(!(int)$auth['admin'])throw new DomainException('Client administration is required');$progress=(int)($r['progress_id']??0);$complete=(int)($r['complete']??0);if($progress<1||!in_array($complete,[0,1],true))throw new InvalidArgumentException('Invalid setup progress');
            $db->query("UPDATE client_progress SET complete=$complete WHERE user_id=$uid AND client_id=$cid AND progress_id=$progress");return ModuleFlowRuntime::response(0,'Setup progress saved');
        }
        if(in_array($action,['changePassword','changeMpin','changeMPIN','changeMPin'],true)){require_once __DIR__.'/ModuleCredentials.php';return ModuleCredentials::change($action==='changePassword'?'password':'mpin',$r);}
        if($action==='selectUser')return self::selectUser($r);
        if($action==='select'){if(!(new session())->access($uid,(new dbconnection())->module('Employee'),1))throw new DomainException('Employee access is required');}
        if($action==='selectRoleTemplate'){
            if(!(new session())->access($uid,(new dbconnection())->module('Employee'),3))throw new DomainException('Employee permission administration is required');
            $role=(string)($r['role']??'');
            $modules=$db->query('SELECT module_id FROM client_module WHERE client_id='.$cid.' AND active=1')->fetch_all(MYSQLI_ASSOC);
            if(ctype_digit($role) || (is_numeric($role) && (int)$role > 0)){
                $posId=(int)$role;
                $ptRows=$db->query('SELECT module_id,permission FROM position_template WHERE epos_id='.$posId)->fetch_all(MYSQLI_ASSOC);
                $levels=[];foreach($ptRows as $row)$levels[(int)$row['module_id']]=(int)$row['permission'];
                $levels=ModuleRolePolicy::dependencies($levels);
                $activeIds=array_map(fn($m)=>(int)$m['module_id'],$modules);
                return ModuleFlowRuntime::response(0,'','',array_intersect_key($levels,array_flip($activeIds)));
            }
            if(!in_array($role,['trainer','admin','super'],true))throw new InvalidArgumentException('Unknown role template');
            if($role==='super' && !(int)$auth['admin'] && !in_array((int)($auth['position_id']??0),[1,11],true))throw new DomainException('Super Admin template is only accessible to developer accounts');
            return ModuleFlowRuntime::response(0,'','',ModuleRolePolicy::template($role,$modules));
        }
        if($action==='selectNormalizedPermissions'){
            if(!(new session())->access($uid,(new dbconnection())->module('Employee'),3))throw new DomainException('Employee permission administration is required');
            $levels=[];foreach($r['permissions']??[] as $p){$mid=(int)($p['module_id']??0);$level=(int)($p['permission']??0);if($mid<1||$level<0||$level>3)throw new InvalidArgumentException('Invalid permission');$levels[$mid]=$level;}
            return ModuleFlowRuntime::response(0,'','',ModuleRolePolicy::dependencies($levels));
        }
        if(!in_array($action,['selectPermission','selectModulePermission','selectReportPermission','updatePermission','updateModulePermission','updateReportPermission'],true))return null;
        $target=(int)($r['user_id']??0);
        $stmt=$db->prepare('SELECT id,contact_id,CONCAT_WS(\' \',fname,lname) name FROM user WHERE id=? AND client_id=? AND type IN(\'E\',\'F\') AND park=0');$stmt->bind_param('ii',$target,$cid);$stmt->execute();$user=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$user)throw new DomainException('The employee login is not in this tenant');
        $session=new session();$employeeModule=(new dbconnection())->module('Employee');
        if(!$session->access($uid,$employeeModule,3) && !(int)$auth['admin'])throw new DomainException('Employee permission administration is required');
        if(str_starts_with($action,'update') && $target===$uid && !(int)$auth['admin'])throw new DomainException('You cannot change your own access');
        $bids=array_map('intval',(array)($r['bids']??[$r['bid']??$r['b_id']??get_bid()]));$bids=array_values(array_unique($bids));
        if(!$bids || min($bids)<1)throw new InvalidArgumentException('Select at least one branch');
        $allowed=(int)$auth['admin']?array_map('intval',array_column($db->query('SELECT id FROM branch WHERE park=0')->fetch_all(MYSQLI_ASSOC),'id')):$auth['allowed_bids'];
        foreach($bids as $bid)if(!in_array($bid,$allowed,true))throw new DomainException('You cannot manage one of the selected branches');
        foreach(array_unique(array_map('intval',(array)($r['removed_bids']??[]))) as $removed){
            if(str_starts_with($action,'select'))throw new InvalidArgumentException('Branch removal requires a save');
            if(!in_array($removed,$allowed,true)||in_array($removed,$bids,true))throw new DomainException('Invalid branch removal');
            $db->query('DELETE FROM user_permission WHERE user_id='.$target.' AND bid='.$removed);$db->query('DELETE FROM user_report_permission WHERE user_id='.$target.' AND bid='.$removed);$db->query('DELETE FROM user_bid WHERE user_id='.$target.' AND bid='.$removed);
            $db->query('DELETE FROM employee_bid WHERE bid='.$removed.' AND employee_id IN(SELECT id FROM employee WHERE contact_id='.(int)$user['contact_id'].')');
        }
        $bid=(int)($r['bid']??$r['b_id']??get_bid());
        $branchRows=$db->query('SELECT DISTINCT bid FROM user_bid WHERE user_id='.$target)->fetch_all(MYSQLI_ASSOC);
        $userBids=array_map('intval',array_column($branchRows,'bid'));
        if(str_starts_with($action,'select')) {
            if($userBids && !in_array($bid,$userBids,true)){$bid=$userBids[0];}
            if($action==='selectReportPermission'){
                $mods=array_map('intval',(array)($r['module_ids']??[]));if(!$mods)$mods=[0];$list=implode(',',$mods);
                // ADR-029: Expand module list to include child modules (e.g. Member 18, Payment 19, Workshift 22)
                // whose parent modules are selected, ensuring child-module reports can be managed in report permissions.
                $childMods=array_map('intval',array_column($db->query("SELECT id FROM `ccc_central`.module WHERE parent_id IN ($list)")->fetch_all(MYSQLI_ASSOC),'id'));
                $allMods=array_unique(array_merge($mods,$childMods));$list=implode(',',$allMods);
                $perms=$db->query("SELECT r.id,r.module_id,r.name,r.title,COALESCE(urp.permission,0) permission FROM report r LEFT JOIN user_report_permission urp ON urp.report_id=r.id AND urp.user_id=$target AND urp.bid=$bid WHERE r.report=1 AND r.module_id IN($list) AND EXISTS(SELECT cm.id FROM client_module cm WHERE cm.client_id=$cid AND cm.module_id=r.module_id AND cm.active=1)")->fetch_all(MYSQLI_ASSOC);
                return ModuleFlowRuntime::response(0,'','',['contact_id'=>$user['contact_id'],'name'=>$user['name'],'permissions'=>$perms,'bids'=>$userBids,'bid'=>$bid]);
            }
            $stmt=$db->prepare('SELECT cm.module_id,cm.module,COALESCE(up.permission,0) permission,? bid FROM client_module cm JOIN `ccc_central`.module m ON m.id=cm.module_id LEFT JOIN user_permission up ON up.cm_id=cm.id AND up.user_id=? AND up.bid=? WHERE cm.client_id=? AND cm.active=1 AND m.park=0 AND m.parent_id=0 ORDER BY cm.module');
            $stmt->bind_param('iiii',$bid,$target,$bid,$cid);$stmt->execute();$perms=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            return ModuleFlowRuntime::response(0,'','',['contact_id'=>$user['contact_id'],'name'=>$user['name'],'permissions'=>$perms,'bids'=>$userBids,'bid'=>$bid]);
        }
        if($action==='updateReportPermission') {
            foreach($bids as $b){
                $db->query('DELETE FROM user_report_permission WHERE user_id='.$target.' AND bid='.$b);
                $stmt=$db->prepare('INSERT INTO user_report_permission(user_id,report_id,permission,bid) VALUES(?,?,?,?)');
                foreach((array)($r['u']??[]) as $p){$rid=(int)($p['id']??0);$level=(int)($p['permission']??0);if($rid<1||$level<0||$level>3)throw new InvalidArgumentException('Invalid report permission');
                    $report=$db->query("SELECT r.id FROM report r JOIN client_module cm ON cm.module_id=r.module_id WHERE r.id=$rid AND r.report=1 AND cm.client_id=$cid AND cm.active=1")->fetch_assoc();if(!$report)throw new DomainException('Report is not available in this tenant');
                    if(!(int)$auth['admin'] && $level>(int)$db->query("SELECT COALESCE(MAX(permission),0) FROM user_report_permission WHERE user_id=$uid AND report_id=$rid AND (bid=$b OR bid=".(int)get_bid().")")->fetch_row()[0])throw new DomainException('You cannot grant report access greater than your own');
                    $stmt->bind_param('iiii',$target,$rid,$level,$b);$stmt->execute();}$stmt->close();
            }
            $db->query('UPDATE user SET auth_epoch=auth_epoch+1 WHERE id='.$target);
            return ModuleFlowRuntime::response(0,'Report permissions saved');
        }
        $modules=$db->query('SELECT cm.id,cm.module_id,m.parent_id FROM client_module cm JOIN `ccc_central`.module m ON m.id=cm.module_id WHERE cm.client_id='.$cid.' AND cm.active=1')->fetch_all(MYSQLI_ASSOC);
        $cm=[];foreach($modules as $m)$cm[(int)$m['module_id']]=(int)$m['id'];
        $entries=$action==='updateModulePermission'?[$r['u']??[]]:($r['u']??[]);
        $p=[];foreach($entries as $v){$mid=(int)($v['module_id']??0);$level=(int)($v['permission']??-1);if(!isset($cm[$mid]))continue;if($level<0||$level>3)throw new InvalidArgumentException('Invalid module permission');$p[$mid]=$level;}
        $p=ModuleRolePolicy::children(ModuleRolePolicy::dependencies($p),$modules);
        foreach($bids as $b){
            $exists=(int)$db->query('SELECT COUNT(*) FROM user_permission WHERE user_id='.$target.' AND bid='.$b)->fetch_row()[0];
            $values=$r['permissions_by_branch'][(string)$b]??$p;
            if($values===null)continue;
            $values=ModuleRolePolicy::children(ModuleRolePolicy::dependencies($values),$modules);
            foreach($values as $mid=>$level){
                $mid=(int)$mid;$level=(int)$level;if(!isset($cm[$mid]))continue;
                if(!(int)$auth['admin']){
                    $own=$db->query('SELECT COALESCE(MAX(permission),0) FROM user_permission WHERE user_id='.$uid.' AND cm_id='.$cm[$mid].' AND (bid='.$b.' OR bid='.(int)get_bid().')')->fetch_row()[0];
                    if($level>(int)$own)throw new DomainException('You cannot grant access greater than your own');
                }
                $db->query('DELETE FROM user_permission WHERE user_id='.$target.' AND cm_id='.$cm[$mid].' AND bid='.$b);
                $stmt=$db->prepare('INSERT INTO user_permission(user_id,cm_id,permission,bid) VALUES(?,?,?,?)');$stmt->bind_param('iiii',$target,$cm[$mid],$level,$b);$stmt->execute();$stmt->close();
            }
            if(!$db->query('SELECT id FROM user_bid WHERE user_id='.$target.' AND bid='.$b)->num_rows)$db->query('INSERT INTO user_bid(user_id,bid) VALUES('.$target.','.$b.')');
            $employee=$db->query('SELECT id FROM employee WHERE contact_id='.(int)$user['contact_id'])->fetch_assoc();
            if($employee && !$db->query('SELECT id FROM employee_bid WHERE employee_id='.(int)$employee['id'].' AND bid='.$b)->num_rows)$db->query('INSERT INTO employee_bid(employee_id,bid) VALUES('.(int)$employee['id'].','.$b.')');
        }
        $targetUserRow=$db->query('SELECT position_id,admin FROM user WHERE id='.$target)->fetch_assoc();
        $targetPos=(int)($targetUserRow['position_id']??0);
        $isAdminTarget=in_array($targetPos,[1,2,8],true);
        // ADR-029: Ensure user.admin = 1 for admin positions (Owner 1, CXO 2, Admin 8)
        // and guarantee all active tenant reports are seeded across assigned branches.
        if($isAdminTarget){
            $db->query('UPDATE user SET admin=1 WHERE id='.$target.' AND client_id='.$cid);
            foreach($bids as $b){
                $db->query("INSERT IGNORE INTO user_report_permission(user_id,report_id,permission,bid) SELECT $target,r.id,1,$b FROM report r JOIN client_module cm ON cm.module_id=r.module_id WHERE cm.client_id=$cid AND cm.active=1 AND r.report=1");
            }
        }
        $db->query('DELETE FROM notify_user WHERE user_id='.$target);
        $db->query('INSERT INTO notify_user(user_id,module_id) SELECT DISTINCT up.user_id,cm.module_id FROM user_permission up JOIN client_module cm ON cm.id=up.cm_id WHERE up.user_id='.$target.' AND up.permission>=2 AND cm.client_id='.$cid);
        $db->query('UPDATE user SET auth_epoch=auth_epoch+1 WHERE id='.$target);
        return ModuleFlowRuntime::response(0,'Permissions saved');
    }
    private static function selectUser(array $r): array
    {
        $db=ModuleFlowRuntime::db();$uid=(int)get_uid();$cid=(int)ModuleFlowRuntime::$context['client_id'];$filter=$r['filter']??[];
        $cols=$r['col']??['id','fname','lname'];
        $safe=['id','fname','lname','contact_id','client_id','position_id','admin','type','park','inactive','mobile','country_code'];
        if(array_diff($cols,$safe))throw new DomainException('This user field cannot be returned');
        // Compatibility with delete PIN checks: verify server-side and return id,
        // never expose stored PIN/password or permit another user's verification.
        if(!empty($r['orfilter'])){
            $target=0;foreach($filter as $f)if(($f[0]??'')==='id' && ($f[1]??'')==='=')$target=(int)$f[2];
            if($target!==$uid)throw new DomainException('Only your own credential can be verified');
            $row=$db->query('SELECT id,admin,password,mpin FROM user WHERE id='.$uid.' AND client_id='.$cid)->fetch_assoc();$ok=false;
            foreach($r['orfilter'] as $f){if(!in_array($f[0]??'',['password','mpin'],true))throw new InvalidArgumentException('Invalid verification field');$stored=(string)$row[$f[0]];$value=(string)($f[2]??'');$ok=$ok||($stored!==''&&(password_verify($value,$stored)||hash_equals($stored,$value)));}
            return ModuleFlowRuntime::response($ok?0:1,$ok?'Verified':'Invalid credential','',$ok?[array_intersect_key($row,array_flip($cols))]:[]);
        }
        $query=ModuleFlowSql::select($cols).'FROM user WHERE client_id='.$cid;
        if($filter)$query.=' AND '.ModuleFlowSql::clauses($filter);
        return ModuleFlowRuntime::response(0,'','',$db->query($query)->fetch_all(MYSQLI_ASSOC));
    }
}
