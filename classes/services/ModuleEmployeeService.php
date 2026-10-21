<?php
require_once __DIR__.'/ModuleRolePolicy.php';
/** Atomic employee/contact/login/role/hierarchy lifecycle. */
final class ModuleEmployeeService
{
    private static function db(): mysqli { return ModuleFlowRuntime::db(); }
    private static function save(string $table,array $values,?int $id=null): int
    {
        $db=self::db();$columns=array_column($db->query('SHOW COLUMNS FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC),'Field');
        $values=array_intersect_key($values,array_flip($columns));
        $sets=[];foreach($values as $col=>$val)$sets[]=ModuleFlowSql::identifier($col).'='.ModuleFlowSql::value($val);
        if(!$sets)throw new InvalidArgumentException('No employee changes supplied');
        $db->query(($id===null?'INSERT INTO ':'UPDATE ').'`'.$table.'` SET '.implode(',',$sets).($id===null?'':' WHERE id='.$id));
        return $id??(int)$db->insert_id;
    }
    private static function selected(array $r,string $key='filter'): array
    {
        $where=ModuleFlowSql::clauses($r[$key]??[]);$rows=self::db()->query('SELECT * FROM employee WHERE '.$where.' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        if(count($rows)!==1)throw new InvalidArgumentException('Select one employee');
        $row=$rows[0];$id=(int)$row['id'];$bid=(int)get_bid();
        if(!self::db()->query("SELECT id FROM employee_bid WHERE employee_id=$id AND bid=$bid")->num_rows)throw new DomainException('Employee is outside this branch');
        return $row;
    }
    public static function insert(array $r): array
    {
        ModuleFlowRuntime::lock('employee-onboarding');$db=self::db();$cid=(int)($r['contact_id']??0);$bid=(int)get_bid();
        self::roleAuthority((int)($r['position_id']??0));
        self::emergency($r,$cid);
        if(!$cid){$res=json_decode((new contact())->insert_contact($r),true);if(($res['error']??1)!==0)throw new DomainException($res['message']??'Contact could not be added');$cid=(int)($res['data']['id']??0);}
        if(!$cid||!$db->query("SELECT id FROM contact WHERE id=$cid AND bid=$bid AND park=0")->num_rows)throw new DomainException('Select an active contact in this branch');
        if($db->query("SELECT id FROM employee WHERE contact_id=$cid")->num_rows)throw new DomainException('Employee already exists for this contact');
        $values=array_intersect_key($r,array_flip(['position_id','grade','doj','user_account','workshift_id','workshift_in_time','workshift_out_time','target_id','assignment_check','assignment_no','salary','calculate_salary','incentive_new','incentive_renew','p_incentive_c','p_incentive_sc','allowance','tds_type','tds_percent','rate_multiplier','type','qualifier']));
        foreach(['assign_inq'=>'auto_assign_inq','departments'=>'department_id','salary_type_value'=>'salary_type'] as $from=>$to)if(isset($r[$from]))$values[$to]=$r[$from];
        $values+=['contact_id'=>$cid,'ecode'=>(int)$db->query('SELECT COALESCE(MAX(ecode),0)+1 FROM employee')->fetch_row()[0],'status'=>1,'park'=>0,'created_by'=>get_uid(),'created_at'=>date('Y-m-d H:i:s')];
        if(empty($values['position_id']))throw new InvalidArgumentException('Select an employee position');
        $id=self::save('employee',$values);$db->query("INSERT INTO employee_bid(employee_id,bid) VALUES($id,$bid)");
        self::parents($id,(array)($r['parent_position_id']??[]));
        $db->query("UPDATE contact SET contact_group_id=(SELECT id FROM contact_group WHERE contact_group='Employee' LIMIT 1) WHERE id=$cid");
        $row=$db->query("SELECT * FROM employee WHERE id=$id")->fetch_assoc();$uid=self::account($row,true);
        return ModuleFlowRuntime::response(0,'Employee added; use the password reset flow to set login credentials','',['emp_id'=>$id,'contact_id'=>$cid,'user_id'=>$uid]);
    }
    public static function update(array $r): array
    {
        $old=self::selected($r);$id=(int)$old['id'];$values=[];
        self::emergency($r,(int)$old['contact_id']);
        $allowed=['position_id','department_id','grade','doj','doe','notice_start_date','on_notice','user_account','workshift_id','workshift_in_time','workshift_out_time','target_id','assignment_check','assignment_no','salary','calculate_salary','incentive_new','incentive_renew','p_incentive_c','p_incentive_sc','salary_type','allowance','tds_type','tds_percent','rate_multiplier','type','auto_assign_inq','qualifier','associate'];
        foreach($r['u']??[] as $v){if(!in_array($v['col']??'',$allowed,true))throw new InvalidArgumentException('Unsupported employee change');$values[$v['col']]=$v['val'];}
        $sensitive=false;foreach(['position_id','type','user_account'] as $field)if(array_key_exists($field,$values)&&(string)$values[$field]!== (string)$old[$field])$sensitive=true;
        if(array_key_exists('parent_position_id',$r)){$parseParent=fn($p)=>is_array($p)?(int)($p['id']??$p['emp_id']??$p['parent_emp_id']??0):(int)$p;$parents=array_values(array_filter(array_unique(array_map($parseParent,(array)$r['parent_position_id']))));$before=array_map('intval',array_column(self::db()->query('SELECT parent_emp_id FROM employee_parent WHERE emp_id='.$id.' AND park=0')->fetch_all(MYSQLI_ASSOC),'parent_emp_id'));sort($parents);sort($before);if($parents!==$before)$sensitive=true;}
        if($sensitive){self::administrativeChange((int)$old['contact_id']);if(isset($values['position_id']))self::roleAuthority((int)$values['position_id']);}
        if($values)self::save('employee',$values+['modified_by'=>get_uid()],$id);
        if(!empty($r['update_contact'])){
            // Bind nested contact changes to this employee, never a second client filter.
            $res=json_decode((new contact())->update_contact(['session_id'=>$r['session_id']??'','u'=>$r['update_contact'],'filter'=>[['id','=',(int)$old['contact_id']],['bid','=',get_bid()]]]),true);
            if(($res['error']??1)!==0)throw new DomainException($res['message']??'Contact update failed');
        }
        if(array_key_exists('parent_position_id',$r))self::parents($id,(array)$r['parent_position_id']);
        $row=self::db()->query('SELECT * FROM employee WHERE id='.$id)->fetch_assoc();
        $roleChanged=(int)$old['position_id']!==(int)$row['position_id'] || (int)$old['type']!==(int)$row['type'];
        $uid=self::account($row,$roleChanged);
        return ModuleFlowRuntime::response(0,'Employee updated','',['user_id'=>$uid]);
    }
    private static function emergency(array &$r,int $contact): void
    {
        $e=$r['emergency']??['name'=>$r['ename']??'','mobile'=>$r['emobile']??'','country_code'=>$r['ecountry_code']??'91','relation'=>$r['relation']??''];
        if(empty($e['name'])&&empty($e['mobile']))return;
        $mobile=preg_replace('/[+\s()-]+/','',(string)($e['mobile']??''));$country=ltrim((string)($e['country_code']??'91'),'+');
        if(!preg_match('/^\d{8,15}$/D',$mobile)||!preg_match('/^\d{1,4}$/D',$country)||empty($e['name'])||empty($e['relation']))throw new InvalidArgumentException('Complete the emergency contact name, country, mobile and relationship');
        $db=self::db();$bid=(int)get_bid();$stmt=$db->prepare("SELECT id FROM contact WHERE mobile=? AND REPLACE(country_code,'+','')=? AND bid=? AND park=0");$stmt->bind_param('ssi',$mobile,$country,$bid);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if(count($rows)>1)throw new DomainException('Duplicate emergency contact identity needs reconciliation');
        $parent=(int)($rows[0]['id']??0);
        if(!$parent){$name=explode(' ',trim($e['name']),2);$group=(int)($db->query("SELECT id FROM contact_group WHERE contact_group='Contact' LIMIT 1")->fetch_row()[0]??0);if(!$group)throw new DomainException('Configure the Contact group before adding an emergency contact');$res=json_decode((new contact())->insert_contact(['fname'=>$name[0],'lname'=>$name[1]??'','mobile'=>$mobile,'country_code'=>$country,'contact_group_id'=>$group,'bid'=>$bid]),true);if((int)($res['error']??1)!==0)throw new DomainException('Emergency contact could not be saved');$parent=(int)($res['data']['id']??$res['data']['contact_id']??0);}
        if(!$parent||$parent===$contact)throw new DomainException('Emergency contact must be a different person');
        $r['parent_id']=$parent;$r['relation']=$e['relation'];
        if($contact){$relation=(string)$e['relation'];$stmt=$db->prepare('UPDATE contact SET parent_id=?,relation=? WHERE id=? AND bid=?');$stmt->bind_param('isii',$parent,$relation,$contact,$bid);$stmt->execute();$stmt->close();if(!empty($r['update_contact']))foreach($r['update_contact'] as &$v){if($v['col']==='parent_id')$v['val']=$parent;if($v['col']==='relation')$v['val']=$relation;}unset($v);}
    }
    public static function park(array $r): array
    {
        $rows=self::db()->query('SELECT * FROM employee WHERE '.ModuleFlowSql::clauses($r['d']??[]).' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        if(!$rows)throw new InvalidArgumentException('Select an employee');$park=(int)($r['park_value']??1);if(!in_array($park,[0,1],true))throw new InvalidArgumentException('Invalid employee state');
        foreach($rows as $row){
            $id=(int)$row['id'];
            if($park===1){
                $activeBatches=self::db()->query('SELECT b.batch FROM batch b JOIN batch_employee be ON be.batch_id=b.id WHERE be.contact_id='.(int)$row['contact_id'].' AND b.park=0 AND be.park=0 AND b.end_date >= CURDATE() LIMIT 5')->fetch_all(MYSQLI_ASSOC);
                if(!empty($activeBatches)){
                    $names=implode(', ', array_column($activeBatches, 'batch'));
                    throw new DomainException('Cannot deactivate employee while assigned as teacher to active batch(es): '.$names.'. Reassign or conclude these batches first.');
                }
            }
            self::save('employee',['park'=>$park,'modified_by'=>get_uid()],$id);$row['park']=$park;self::account($row,false);
        }
        return ModuleFlowRuntime::response(0,$park?'Employee removed and login access revoked':'Employee restored; devices must sign in again');
    }
    public static function parentsRequest(array $r): array
    {
        $row=self::selected(['filter'=>[['id','=',(int)($r['employee_id']??0)]]]);self::administrativeChange((int)$row['contact_id']);self::parents((int)$row['id'],(array)($r['val']??[]));
        return ModuleFlowRuntime::response(0,'Reporting employees updated');
    }
    private static function administrativeChange(int $contact): void
    {
        $auth=ModuleFlowRuntime::$context;
        if(!(new session())->access(get_uid(),(new dbconnection())->module('Employee'),3))throw new DomainException('Employee administration is required for role, login or reporting changes');
        if($contact===(int)$auth['contact_id']&&!(int)$auth['admin'])throw new DomainException('Another administrator must change your role, login access or reporting hierarchy');
    }
    private static function roleAuthority(int $position): void
    {
        if($position<1)throw new InvalidArgumentException('Select an employee position');$auth=ModuleFlowRuntime::$context;
        if(in_array($position,[1,2,8,11],true)&&!(int)$auth['admin']&&!in_array((int)$auth['position_id'],[1,2,8,11],true))throw new DomainException('A privileged administrator must assign this policy role');
    }
    private static function parents(int $id,array $parents): void
    {
        ModuleFlowRuntime::lock('employee-hierarchy');$parseParent=fn($p)=>is_array($p)?(int)($p['id']??$p['emp_id']??$p['parent_emp_id']??0):(int)$p;$parents=array_values(array_filter(array_unique(array_map($parseParent,$parents))));
        if(count($parents)>3)throw new InvalidArgumentException('Select at most three reporting employees');
        $db=self::db();$bid=(int)get_bid();$edges=[];foreach($db->query('SELECT emp_id,parent_emp_id FROM employee_parent WHERE park=0')->fetch_all(MYSQLI_ASSOC) as $e)$edges[(int)$e['emp_id']][]=(int)$e['parent_emp_id'];$edges[$id]=$parents;
        foreach($parents as $p){if($p===$id||!$db->query("SELECT e.id FROM employee e JOIN employee_bid eb ON eb.employee_id=e.id WHERE e.id=$p AND eb.bid=$bid AND e.park=0 AND e.status=1")->num_rows)throw new DomainException('Choose an active reporting employee in this branch');$seen=[];$todo=[$p];while($todo){$n=array_pop($todo);if($n===$id)throw new DomainException('The reporting hierarchy would contain a cycle');if(isset($seen[$n]))continue;$seen[$n]=true;foreach($edges[$n]??[] as $next)$todo[]=$next;}}
        $db->query('UPDATE employee_parent SET park=1 WHERE emp_id='.$id.' AND park=0');foreach($parents as $p)$db->query("INSERT INTO employee_parent(emp_id,parent_emp_id) VALUES($id,$p)");
    }
    private static function account(array $e,bool $resetRole): int
    {
        $db=self::db();$cid=(int)ModuleFlowRuntime::$context['client_id'];$contact=(int)$e['contact_id'];$bid=(int)get_bid();$position=(int)$e['position_id'];
        $c=$db->query('SELECT * FROM contact WHERE id='.$contact)->fetch_assoc();
        $u=$db->query("SELECT * FROM user WHERE client_id=$cid AND contact_id=$contact AND type IN ('E','F') FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        if(count($u)>1)throw new DomainException('Duplicate employee logins need reconciliation before this change');
        $active=empty($e['park']) && (int)($e['user_account']??0)===1 && (int)($e['status']??1)===1;
        if(!$u&&!$active)return 0;$type=(int)($e['type']??0)===1?'F':'E';$country=(string)($c['country_code']??'+91');$mobile=(string)$c['mobile'];
        if(!$u){
            // ADR-029: Admin positions (1=Owner, 2=CXO, 8=Admin) automatically receive user.admin = 1
            // for super-admin parity across reporting, batch management, and branch-level operations.
            $isAdminRole=in_array($position,[1,2,8],true)?1:0;
            $secret=password_hash('1234',PASSWORD_DEFAULT);$fname=(string)$c['fname'];$lname=(string)$c['lname'];
            $stmt=$db->prepare('INSERT INTO user(fname,lname,password,mpin,mobile,country_code,type,contact_id,client_id,position_id,admin,signup,is_first_login,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,1,1,NOW())');$stmt->bind_param('sssssssiiii',$fname,$lname,$secret,$secret,$mobile,$country,$type,$contact,$cid,$position,$isAdminRole);$stmt->execute();$uid=(int)$db->insert_id;$stmt->close();$resetRole=true;
        }else {
            $uid=(int)$u[0]['id'];
            // ADR-029: Synchronize user.admin flag whenever employee position is updated
            $isAdminRole=in_array($position,[1,2,8],true)?1:0;
        }
        $park=$active?0:1;$fname=(string)$c['fname'];$lname=(string)$c['lname'];
        $stmt=$db->prepare('UPDATE user SET fname=?,lname=?,mobile=?,country_code=?,position_id=?,admin=?,type=?,park=? WHERE id=? AND client_id=?');$stmt->bind_param('ssssiisiii',$fname,$lname,$mobile,$country,$position,$isAdminRole,$type,$park,$uid,$cid);$stmt->execute();$stmt->close();
        if(!$active || $resetRole || ($u && (int)$u[0]['park']!==$park))$db->query('UPDATE user SET auth_epoch=auth_epoch+1 WHERE id='.$uid);
        if(!$active){$db->query("UPDATE user_device SET park=1,logout=1 WHERE user_id=$uid");$db->query("UPDATE user_social SET park=1 WHERE user_id=$uid");return $uid;}
        if(!$db->query("SELECT id FROM user_bid WHERE user_id=$uid AND bid=$bid")->num_rows)$db->query("INSERT INTO user_bid(user_id,bid) VALUES($uid,$bid)");
        if($resetRole){
            $modules=$db->query("SELECT cm.id,cm.module_id,m.parent_id,COALESCE(pt.permission,0) permission FROM client_module cm JOIN `ccc_central`.module m ON m.id=cm.module_id LEFT JOIN position_template pt ON pt.module_id=cm.module_id AND pt.epos_id=$position WHERE cm.client_id=$cid AND cm.active=1")->fetch_all(MYSQLI_ASSOC);$levels=[];foreach($modules as $m)$levels[(int)$m['module_id']]=(int)$m['permission'];
            // ADR-029: Apply role-based policy: 'admin' grants level 3 across operational modules
            if(in_array($position,[18,19,44,47],true))$levels=ModuleRolePolicy::template('trainer',$modules);elseif(in_array($position,[1,2,8,11],true))$levels=ModuleRolePolicy::template('admin',$modules);else $levels=ModuleRolePolicy::dependencies($levels);$levels=ModuleRolePolicy::children($levels,$modules);
            // A role change replaces effective access in every assigned branch, removing stale grants.
            $branches=array_map('intval',array_column($db->query("SELECT DISTINCT bid FROM user_bid WHERE user_id=$uid")->fetch_all(MYSQLI_ASSOC),'bid'));
            foreach($branches as $b){
                $db->query("DELETE FROM user_permission WHERE user_id=$uid AND bid=$b");
                foreach($modules as $m){
                    $cm=(int)$m['id'];$level=(int)($levels[(int)$m['module_id']]??0);
                    if(!(int)ModuleFlowRuntime::$context['admin']){$own=(int)$db->query('SELECT COALESCE(MAX(permission),0) FROM user_permission WHERE user_id='.(int)get_uid()." AND cm_id=$cm AND bid=$b")->fetch_row()[0];if($level>$own)throw new DomainException('The role requires access you cannot grant in an assigned branch');}
                    $db->query("INSERT INTO user_permission(user_id,cm_id,permission,bid) VALUES($uid,$cm,$level,$b)");
                }
                // ADR-029: Auto-seed all active reports in user_report_permission for admin users
                // to prevent blank report/list screens and grant full operational visibility.
                if($isAdminRole){
                    $db->query("DELETE FROM user_report_permission WHERE user_id=$uid AND bid=$b");
                    $db->query("INSERT INTO user_report_permission(user_id,report_id,permission,bid) SELECT $uid,r.id,1,$b FROM report r JOIN client_module cm ON cm.module_id=r.module_id WHERE cm.client_id=$cid AND cm.active=1 AND r.report=1");
                }
            }
            $db->query("DELETE FROM notify_user WHERE user_id=$uid");$db->query("INSERT INTO notify_user(user_id,module_id) SELECT DISTINCT up.user_id,cm.module_id FROM user_permission up JOIN client_module cm ON cm.id=up.cm_id WHERE up.user_id=$uid AND cm.client_id=$cid AND up.permission>=2");
        }
        return $uid;
    }
}
