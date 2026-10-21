<?php
final class ModuleStaffRequestPolicy
{
    public static function guard(string $action,array &$r): void
    {
        $db=ModuleFlowRuntime::db();$contact=(int)ModuleFlowRuntime::$context['contact_id'];$bid=(int)get_bid();
        $actor=$db->query("SELECT e.id FROM employee e JOIN employee_bid eb ON eb.employee_id=e.id WHERE e.contact_id=$contact AND eb.bid=$bid AND e.park=0 AND e.status=1")->fetch_assoc();
        if(!$actor)throw new DomainException('An active employee account is required');$emp=(int)$actor['id'];$_SESSION['employee_id']=$emp;
        if($action==='insert'){
            $parent=(int)($r['parent_id']??0);
            if(!$db->query("SELECT ep.emp_id FROM employee_parent ep JOIN employee p ON p.id=ep.parent_emp_id JOIN employee_bid eb ON eb.employee_id=p.id WHERE ep.emp_id=$emp AND ep.parent_emp_id=$parent AND p.park=0 AND p.status=1 AND eb.bid=$bid")->num_rows)throw new DomainException('Choose an assigned reporting employee in this branch');
            foreach(['start_date','end_date'] as $k){if($k==='end_date'&&!empty($r['singledate']))$r[$k]=$r['start_date'];$d=DateTime::createFromFormat('!Y-m-d',(string)($r[$k]??''));if(!$d||$d->format('Y-m-d')!==$r[$k])throw new InvalidArgumentException('Choose valid request dates');}
            if($r['end_date']<$r['start_date'])throw new InvalidArgumentException('End date must follow start date');
            if(!in_array((int)($r['request_type']??-1),range(0,8),true))throw new InvalidArgumentException('Invalid request type');
            foreach(['time','break','singledate'] as $k)$r[$k]=(int)($r[$k]??0);
            unset($r['filter'],$r['order'],$r['u'],$r['d']);return;
        }
        if($action==='statusUpdate'){
            $id=(int)($r['row_id']??0);$row=$db->query("SELECT * FROM emp_att_request WHERE id=$id AND park=0 FOR UPDATE")->fetch_assoc();
            if(!$row||(int)$row['parent_id']!==$emp||(int)$row['emp_id']===$emp||(int)$row['status']!==0)throw new DomainException('Only the assigned approver can decide a pending request');
            if(!in_array((int)($r['request_status']??0),[1,2],true))throw new InvalidArgumentException('Choose approve or reject');$r['emp_id']=$emp;return;
        }
        if(in_array($action,['update','delete'],true)){
            $key=$action==='delete'?'d':'filter';$filter=$r[$key]??[];if(!$filter)throw new InvalidArgumentException('Select a request');
            $rows=$db->query('SELECT * FROM emp_att_request WHERE '.ModuleFlowSql::clauses($filter).' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);if(!$rows)throw new InvalidArgumentException('Request not found');
            foreach($rows as $row)if((int)$row['emp_id']!==$emp||(int)$row['status']!==0)throw new DomainException('Only your pending requests can be edited or withdrawn');
            foreach($r['u']??[] as $v)if(!in_array($v['col']??'',['request_comment','in_time','out_time'],true))throw new DomainException('Approval, ownership and dates cannot be changed by a generic edit');
            if($action==='delete'&&(int)($r['park_value']??1)!==1)throw new DomainException('Submit a new request instead of restoring a withdrawn request');
        }
        if($action==='select'){$ids=array_map('intval',array_column($db->query("SELECT id FROM emp_att_request WHERE emp_id=$emp OR parent_id=$emp")->fetch_all(MYSQLI_ASSOC),'id'));$r['filter'][]=['id','IN',$ids];}
    }
}
