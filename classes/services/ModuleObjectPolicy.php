<?php
/** Object authorization for named actions as well as generic mutations. */
final class ModuleObjectPolicy
{
    public static function guard(string $route,string $action,array &$r,bool $writing): void
    {
        $db=ModuleFlowRuntime::db();$bid=(int)get_bid();
        if($route==='invoice' && $action==='selectInvoiceLog'){
            $r['filter']=array_values(array_filter($r['filter']??[],fn($f)=>($f[0]??'')!=='bid'));
            $ids=array_map('intval',array_column($db->query('SELECT id FROM invoice WHERE bid='.$bid)->fetch_all(MYSQLI_ASSOC),'id'));$r['filter'][]=['invoice_id','IN',$ids];
        }
        if(!$writing)return;
        if($route==='member'&&$action==='transferMember'){
            $transfer=(int)($r['id']??0);$row=$db->query('SELECT * FROM member_transfer WHERE id='.$transfer.' FOR UPDATE')->fetch_assoc();
            if(!$row||(int)$row['from_bid']!==$bid||(int)$row['status']!==0)throw new DomainException('Select a pending transfer from this branch');
            $r['to_bid']=(int)$row['to_bid'];$r['to_batch']=(int)$row['to_batch'];$r['to_category_id']=(int)$row['to_category'];$r['invoice_id']=(int)$row['invoice_id'];$r['contact_id']=(int)$row['contact_id'];
            $invoice=(int)$row['invoice_id'];if((int)$db->query("SELECT COUNT(*) FROM invoice_item WHERE invoice_id=$invoice AND park=0")->fetch_row()[0]!==1)throw new DomainException('A multi-item invoice needs a reviewed package transfer; it cannot be moved as one membership');
        }
        if(isset($r['from_bid']) && (int)$r['from_bid']!==$bid)throw new DomainException('Transfer source must be the authenticated branch');
        if(!empty($r['to_bid'])){
            $to=(int)$r['to_bid'];if(!in_array($to,ModuleFlowRuntime::$context['allowed_bids'],true)||!$db->query("SELECT id FROM branch WHERE id=$to AND park=0")->num_rows)throw new DomainException('Destination branch is not authorized');
            $uid=(int)get_uid();$cid=(int)ModuleFlowRuntime::$context['client_id'];$name=$route==='inquiry'?'Inquiry':'Member';$mid=(int)(new dbconnection())->module($name);
            if(!$db->query("SELECT up.id FROM user_permission up JOIN client_module cm ON cm.id=up.cm_id WHERE up.user_id=$uid AND cm.client_id=$cid AND cm.module_id=$mid AND up.bid=$to AND up.permission>=2")->num_rows)throw new DomainException('Edit access is required in the destination branch');
            if(!empty($r['to_batch'])){$batch=(int)$r['to_batch'];$destination=$db->query("SELECT category_id FROM batch WHERE id=$batch AND bid=$to AND park=0")->fetch_assoc();if(!$destination||(!empty($r['to_category_id'])&&(int)$destination['category_id']!==(int)$r['to_category_id']))throw new DomainException('Destination batch and category do not match');}
        }
        if(!empty($r['invoice_id'])&&!empty($r['contact_id'])){$invoice=(int)$r['invoice_id'];$contact=(int)$r['contact_id'];if(!$db->query("SELECT id FROM invoice WHERE id=$invoice AND contact_id=$contact")->num_rows)throw new DomainException('The invoice does not belong to the selected contact');}
        foreach(['invoice_id'=>'invoice','batch_id'=>'batch','contact_id'=>'contact','payment_id'=>'payment'] as $key=>$table){
            if($route==='employee'&&$action==='insert'&&$key==='contact_id'&&empty($r[$key]))continue;
            if($route==='batch'&&$key==='contact_id')continue;
            $id=(int)($r[$key]??0);if(!$id)continue;
            $row=$db->query('SELECT bid FROM `'.$table.'` WHERE id='.$id)->fetch_assoc();if(!$row||(int)$row['bid']!==$bid)throw new DomainException('The referenced '.$table.' is outside this branch');
        }
        foreach(['from_item_id','to_item_id','item_id'] as $key){
            $id=(int)($r[$key]??0);if(!$id)continue;$expected=$key==='to_item_id'&&$route==='member'?(int)($r['to_bid']??$bid):$bid;
            $row=$db->query('SELECT i.id,i.bid FROM invoice i JOIN invoice_item ii ON ii.invoice_id=i.id WHERE ii.id='.$id)->fetch_assoc();
            if(!$row||(int)$row['bid']!==$expected||(!empty($r['invoice_id'])&&(int)$r['invoice_id']!==(int)$row['id']))throw new DomainException('The referenced package does not belong to this invoice and branch');
        }
        foreach($r['u']??[] as $v){
            $col=$v['col']??'';
            if($route==='batch'&&$col==='contact_id')continue;
            if($col==='bid'&&(int)($v['val']??0)===$bid)continue;
            if(in_array($col,['bid','client_id','contact_id','invoice_id','user_id','created_by','created_at'],true))throw new DomainException('Ownership cannot be changed by a generic edit');
            if($route==='invoice'&&$action==='update'&&!in_array($col,['notes','invoice_comment','modified_by','employee_id'],true))throw new DomainException('Use a validated billing operation for financial changes');
        }
    }
}
