<?php
final class ModuleInquiryTransfer
{
    public static function transfer(array $r): array
    {
        $db=ModuleFlowRuntime::db();$id=(int)($r['inquiry_id']??0);$to=(int)($r['to_bid']??0);$from=(int)get_bid();
        if($to===$from || !in_array($to,ModuleFlowRuntime::$context['allowed_bids'],true))throw new DomainException('Choose a destination branch you can manage');
        $cid=(int)ModuleFlowRuntime::$context['client_id'];$uid=(int)get_uid();$mid=(int)(new dbconnection())->module('Inquiry');
        if(!$db->query("SELECT up.id FROM user_permission up JOIN client_module cm ON cm.id=up.cm_id WHERE cm.client_id=$cid AND cm.module_id=$mid AND up.user_id=$uid AND up.bid=$to AND up.permission>=2")->num_rows)throw new DomainException('Inquiry edit access is required in the destination branch');
        $row=$db->query("SELECT * FROM inquiry WHERE id=$id AND bid=$from AND park=0 FOR UPDATE")->fetch_assoc();if(!$row)throw new DomainException('Inquiry is not available in this branch');$contact=(int)$row['contact_id'];
        if($db->query("SELECT id FROM invoice WHERE contact_id=$contact AND park=0 AND quotation=0")->num_rows || $db->query("SELECT id FROM inquiry WHERE contact_id=$contact AND id<>$id AND park=0")->num_rows)throw new DomainException('Resolve the other active inquiry or membership before moving this contact');
        $categories=$db->query("SELECT ic.*,c.master_category_id FROM inquiry_category ic JOIN category c ON c.id=ic.category_id WHERE ic.inquiry_id=$id AND ic.park=0 FOR UPDATE")->fetch_all(MYSQLI_ASSOC);if(!$categories)throw new DomainException('Inquiry has no transferable category');$mapping=[];
        foreach($categories as $cat){$master=(int)$cat['master_category_id'];$matches=$db->query("SELECT id FROM category WHERE master_category_id=$master AND bid=$to AND park=0")->fetch_all(MYSQLI_ASSOC);if(count($matches)!==1)throw new DomainException('Each interest needs exactly one matching category in the destination branch');$mapping[(int)$cat['id']]=(int)$matches[0]['id'];}
        $employee=(int)($r['employee_id']??$row['c_employee_id']);
        if(!$db->query("SELECT e.id FROM employee e JOIN employee_bid eb ON eb.employee_id=e.id WHERE e.id=$employee AND eb.bid=$to AND e.park=0 AND e.status=1")->num_rows)throw new DomainException('Choose an active destination counselor');$pending=$db->query("SELECT id,employee_id FROM followup WHERE master=0 AND master_id=$id AND unfollow=0 AND park=0 FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        foreach($pending as $f){$owner=$employee?:((int)$f['employee_id']);if(!$db->query("SELECT e.id FROM employee e JOIN employee_bid eb ON eb.employee_id=e.id WHERE e.id=$owner AND eb.bid=$to AND e.park=0 AND e.status=1")->num_rows)throw new DomainException('Choose an active destination counselor for the reminder');ModuleFollowupService::cancelJobs($db,(int)$f['id'],$from);$db->query('UPDATE followup SET employee_id='.$owner.' WHERE id='.(int)$f['id']);}
        foreach($mapping as $interest=>$category)$db->query("UPDATE inquiry_category SET category_id=$category,bid=$to,package_id=0,offer_id=0 WHERE id=$interest");
        $db->query("UPDATE contact SET bid=$to WHERE id=$contact AND bid=$from");$db->query("UPDATE inquiry SET bid=$to,c_employee_id=$employee,allocation_date=NOW(),modified_by=$uid WHERE id=$id");$db->query("UPDATE followup SET bid=$to WHERE master=0 AND master_id=$id");
        $GLOBALS['ccc_authenticated_context']['bid']=$to;
        try{foreach($pending as $f)ModuleFollowupService::schedule($db,(int)$f['id']);}finally{$GLOBALS['ccc_authenticated_context']['bid']=$from;}
        $audit=json_encode(['inquiry_id'=>$id,'from_branch'=>$from,'to_branch'=>$to,'original_date'=>$row['doi'],'interests_before'=>$categories,'destination_counselor'=>$employee,'comment'=>(string)($r['comment']??'')]);
        $stmt=$db->prepare("INSERT INTO module_flow_outbox(actor_id,branch_id,channel,payload,state) VALUES(?,?,'AUDIT',?,'RECORDED')");$stmt->bind_param('iis',$uid,$from,$audit);$stmt->execute();$stmt->close();
        // Original inquiry date and each interest row remain intact; no guessed collapse.
        return ModuleFlowRuntime::response(0,'Inquiry and its reminders transferred');
    }
}
