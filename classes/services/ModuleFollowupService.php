<?php
final class ModuleFollowupService
{
    private static bool $completing=false;
    public static function validateUpdate(array $r): void
    {
        $db=ModuleFlowRuntime::db();$rows=$db->query('SELECT * FROM followup WHERE '.ModuleFlowSql::clauses($r['filter']??[]).' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);if(!$rows)throw new InvalidArgumentException('Select a followup');
        $values=[];foreach($r['u']??[] as $v){if(!in_array($v['col']??'',['comment','type','status','interest_string','sub_interest_string','preferences','slot','area','pincode','city','venue_id','followup','unfollow','reminder','employee_id','modified_by'],true))throw new InvalidArgumentException('Unsupported followup change');$values[$v['col']]=$v['val'];}
        foreach($rows as $row){if((int)$row['bid']!==(int)get_bid())throw new DomainException('Followup is outside this branch');$next=array_merge($row,$values);if((int)$row['unfollow']===0&&(int)$next['unfollow']===1&&!in_array((int)$next['status'],[3,4],true)&&!self::$completing)throw new DomainException('Complete this followup and schedule its continuation in one action');
            if(isset($values['employee_id'])){$emp=(int)$values['employee_id'];$bid=(int)get_bid();if(!$db->query("SELECT e.id FROM employee e JOIN employee_bid eb ON eb.employee_id=e.id WHERE e.id=$emp AND eb.bid=$bid AND e.park=0 AND e.status=1")->num_rows)throw new DomainException('Choose an active counselor in this branch');}}
    }
    public static function schedule(mysqli $db,int $id): void
    {
        $row=$db->query('SELECT mobile,email FROM contact_followup_view WHERE id='.$id.' AND park=0')->fetch_assoc();if(!$row)return;
        require_once dirname(__DIR__,2).'/controllers/sms_via_module.php';require_once dirname(__DIR__,2).'/controllers/email_via_module.php';
        $sms=new sms_via_module();$email=new email_via_module();$dbname=$GLOBALS['ccc_authenticated_context']['db_name'];$action=(new dbconnection())->action('Follow up');
        foreach([21,22] as $kind){$sms->save($db,$dbname,1,$kind,'','Follow up',$action,$row['mobile'],'contact_followup_view',$id);if($row['email'])$email->save($db,$dbname,2,$kind,'','Follow up',$action,$row['email'],'contact_followup_view',$id);}
    }
    public static function cancelJobs(mysqli $db,int $id,?int $branch=null): void
    {
        $cid=(int)($GLOBALS['ccc_authenticated_context']['client_id']??0);$bid=$branch??(int)get_bid();
        if($cid<1)throw new DomainException('Tenant context is required for reminder cancellation');
        foreach(['sms_ready','email_ready'] as $table){
            $stmt=$db->prepare("UPDATE `ccc_central`.`$table` SET status=2,park=1 WHERE client_id=? AND bid=? AND table_name='contact_followup_view' AND record_id=?");$stmt->bind_param('iii',$cid,$bid,$id);$stmt->execute();$stmt->close();
        }
        $stmt=$db->prepare("UPDATE `ccc_central`.`request` SET park=1 WHERE client_id=? AND table_name='contact_followup_view' AND row_id=?");$stmt->bind_param('ii',$cid,$id);$stmt->execute();$stmt->close();
    }
    public static function complete(array $r): array
    {
        $db=ModuleFlowRuntime::db();$filter=$r['filter']??[];
        $rows=$db->query('SELECT * FROM followup WHERE '.ModuleFlowSql::clauses($filter).' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        if(count($rows)!==1)throw new InvalidArgumentException('Select one pending followup');$row=$rows[0];
        if((int)$row['bid']!==(int)get_bid())throw new DomainException('Followup belongs to another branch');
        if((int)$row['unfollow']===1 && empty($r['reopen']))throw new DomainException('This followup is already completed; refresh its history');
        require_once dirname(__DIR__,2).'/controllers/followup.php';$controller=new followup();
        $r['u'][]=['col'=>'unfollow','val'=>1];
        self::$completing=true;
        try{$updated=json_decode($controller->update_followup(['u'=>$r['u']??[],'filter'=>[['id','=',(int)$row['id']]]]),true);}finally{self::$completing=false;}
        if((int)($updated['error']??1)!==0)throw new DomainException($updated['message']??'Followup could not be completed');
        $status=(int)$row['status'];foreach($r['u']??[] as $v)if(($v['col']??'')==='status')$status=(int)$v['val'];
        $nextId=0;
        if(!in_array($status,[3,4],true)){
            $next=$r['next']??null;if(!is_array($next)||empty($next['reminder']))throw new InvalidArgumentException('Choose the next reminder before completing this followup');
            $next=array_merge($next,['contact_id'=>$row['contact_id'],'master'=>$row['master'],'master_id'=>$row['master_id'],'parent_id'=>$row['id'],'bid'=>$row['bid'],'comment'=>'','status'=>$status,'unfollow'=>0]);
            $inserted=json_decode($controller->insert_followup($next),true);
            if((int)($inserted['error']??1)!==0)throw new DomainException($inserted['message']??'The next reminder could not be saved');$nextId=(int)$inserted['data']['followup_id'];
        }
        return ModuleFlowRuntime::response(0,'Followup completed','',['followup_id'=>(int)$row['id'],'next_followup_id'=>$nextId]);
    }
    public static function validateInsert(array &$r): void
    {
        $db=ModuleFlowRuntime::db();$bid=(int)get_bid();$contact=(int)($r['contact_id']??0);$emp=(int)($r['employee_id']??0);$master=(int)($r['master']??0);$masterId=(int)($r['master_id']??0);
        if(!$db->query('SELECT id FROM contact WHERE id='.$contact.' AND bid='.$bid.' AND park=0')->num_rows)throw new DomainException('Followup contact is not available in this branch');
        if($emp&&!$db->query('SELECT e.id FROM employee e JOIN employee_bid eb ON eb.employee_id=e.id WHERE e.id='.$emp.' AND e.park=0 AND e.status=1 AND eb.bid='.$bid)->num_rows)throw new DomainException('Choose an active counselor in this branch');
        if($master===0 && !$db->query('SELECT id FROM inquiry WHERE id='.$masterId.' AND contact_id='.$contact.' AND bid='.$bid.' AND park=0')->num_rows)throw new DomainException('The followup must reference its specific inquiry');
        if(in_array($master,[1,2],true)){$table=$master===1?'payment':'invoice_item';if(!$db->query('SELECT x.id FROM '.$table.' x JOIN invoice i ON i.id=x.invoice_id WHERE x.id='.$masterId.' AND i.contact_id='.$contact.' AND i.bid='.$bid.' AND i.park=0')->num_rows)throw new DomainException('Reminder package or payment does not belong to this contact');}
        if(!in_array($master,[0,1,2],true))throw new InvalidArgumentException('Unsupported reminder type');
        if(empty($r['unfollow'])&&!in_array((int)($r['status']??0),[3,4],true)&&empty($r['reminder'])&&empty($r['followup']))throw new InvalidArgumentException('Choose a reminder date before saving');
        if(empty($r['reminder'])&&!empty($r['followup']))$r['reminder']=$r['followup'];
        foreach(['reminder','followup'] as $f){
            if(!empty($r[$f])){
                $ts = strtotime((string)$r[$f]);
                if($ts === false) throw new InvalidArgumentException('Invalid followup date/time');
                $r[$f] = date('Y-m-d H:i:s', $ts);
            }
        }
        foreach(['comment','interest_string','sub_interest_string','preferences','slot','pincode','area','city'] as $f)$r[$f]=$db->real_escape_string((string)($r[$f]??''));
    }
}
