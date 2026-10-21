<?php
/** Validated package edits and their audit row commit together. */
final class ModuleInvoiceChanges
{
    public static function transfer(array $r): array
    {
        if(!(new session())->access(get_uid(),(new dbconnection())->module('Invoice'),2))throw new DomainException('Invoice edit access is required for a package transfer');
        $db=ModuleFlowRuntime::active()?ModuleFlowRuntime::db():(new dbconnection())->connect($_SESSION['db_name']??'ccc_database');$id=(int)($r['item_id']??0);$from=(int)($r['from_id']??0);$to=(int)($r['to_id']??0);$bid=(int)get_bid();
        $row=$db->query("SELECT ii.batch_id FROM invoice_item ii JOIN invoice i ON i.id=ii.invoice_id WHERE ii.id=$id AND i.bid=$bid FOR UPDATE")->fetch_assoc();if(!$row)throw new DomainException('Package is outside this branch');
        if((int)$row['batch_id']===$to && $db->query("SELECT id FROM batch_transfer WHERE item_id=$id AND from_id=$from AND to_id=$to AND bid=$bid")->num_rows)return ModuleFlowRuntime::response(0,'Batch transfer already saved');
        if((int)$row['batch_id']!==$from)throw new DomainException('Package changed since this transfer was opened; refresh it');
        return self::item(['filter'=>[['id','=',$id]],'u'=>[['col'=>'batch_id','val'=>$to]],'log'=>['change'=>6,'comment'=>$r['comment']??'Batch transfer']]);
    }
    public static function item(array $r): array
    {
        $db=ModuleFlowRuntime::active()?ModuleFlowRuntime::db():(new dbconnection())->connect($_SESSION['db_name']??'ccc_database');$rows=$db->query('SELECT ii.*,i.bid,i.contact_id FROM invoice_item ii JOIN invoice i ON i.id=ii.invoice_id WHERE '.ModuleFlowSql::clauses($r['filter']??[],'ii').' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        if(count($rows)!==1)throw new InvalidArgumentException('Select one invoice item');$old=$rows[0];
        if((int)$old['bid']!==(int)get_bid())throw new DomainException('Invoice item is outside this branch');
        $next=$old;$allowed=['start_date','end_date','sessions','sessions_used','batch_id','park','renew','item_change','extend_reason'];
        foreach($r['u']??[] as $v){if(($v['col']??'')==='modified_by')continue;if(!in_array($v['col']??'',$allowed,true))throw new InvalidArgumentException('Unsupported invoice item change');$next[$v['col']]=$v['val'];}
        foreach(['sessions','sessions_used','batch_id','park','renew','item_change'] as $k)if(!ctype_digit((string)$next[$k]))throw new InvalidArgumentException('Invalid package '.$k);
        foreach(['start_date','end_date'] as $k){$d=DateTime::createFromFormat('!Y-m-d',(string)$next[$k]);if(!$d||$d->format('Y-m-d')!==$next[$k])throw new InvalidArgumentException('Invalid package date');}
        if($next['end_date']<$next['start_date'] || ((int)$next['sessions']>0 && (int)$next['sessions_used']>(int)$next['sessions']))throw new InvalidArgumentException('Invalid package dates or session balance');
        if((int)$next['sessions_used']!==(int)$old['sessions_used'])throw new DomainException('Session usage is controlled by attendance; correct its ledger instead');
        if((int)$next['batch_id']!==(int)$old['batch_id']){
            $b=(int)$next['batch_id'];$bid=(int)get_bid();$cat=(int)$old['category_id'];
            $batch=$db->query("SELECT * FROM batch WHERE id=$b AND category_id=$cat AND bid=$bid AND park=0 FOR UPDATE")->fetch_assoc();
            if(!$batch)throw new DomainException('Choose a batch in the same category and branch');
            if($batch['end_date']<$next['end_date'])throw new DomainException('The destination batch ends before this membership');
            $start=$db->real_escape_string($next['start_date']);$end=$db->real_escape_string($next['end_date']);$contact=(int)$old['contact_id'];
            $count=(int)$db->query("SELECT COUNT(DISTINCT i.contact_id) FROM invoice_item ii JOIN invoice i ON i.id=ii.invoice_id WHERE ii.batch_id=$b AND ii.park=0 AND i.park=0 AND i.quotation=0 AND ii.start_date<='$end' AND ii.end_date>='$start' AND i.contact_id<>$contact")->fetch_row()[0];
            if((int)$batch['limit']>0 && $count>=(int)$batch['limit'])throw new DomainException('The destination batch is full');
        }
        $id=(int)$old['id'];$changes=[];foreach($allowed as $k)if((string)$old[$k]!== (string)$next[$k])$changes[]=['col'=>$k,'val'=>$next[$k]];
        if(!$changes)return ModuleFlowRuntime::response(0,'Package already has these values');
        if((int)$next['batch_id']!==(int)$old['batch_id']){ $from=(int)$old['batch_id'];$to=(int)$next['batch_id'];$bid=(int)get_bid();$db->query("INSERT INTO batch_transfer(from_id,to_id,item_id,bid) VALUES($from,$to,$id,$bid)"); }
        if($changes){$db->query(ModuleFlowSql::update($changes,'UPDATE invoice_item SET').' WHERE id='.$id);}
        $log=$r['log']??[];$invoice=(int)$old['invoice_id'];$change=(int)($log['change']??((int)$next['batch_id']!==(int)$old['batch_id']?5:1));$comment=(string)($log['comment']??$r['comment']??'Package edited');
        // An immutable before/after description makes a standalone legacy edit auditable too.
        $comment.="\nBefore: ".json_encode(array_intersect_key($old,array_flip($allowed)))."\nAfter: ".json_encode(array_intersect_key($next,array_flip($allowed)));
        $uid=(int)get_uid();$zero=0;$stmt=$db->prepare('INSERT INTO invoice_change_log(invoice_id,from_item_id,to_item_id,`change`,comment,created_by) VALUES(?,?,?,?,?,?)');$stmt->bind_param('iiiisi',$invoice,$id,$zero,$change,$comment,$uid);$stmt->execute();$logId=(int)$db->insert_id;$stmt->close();
        if($next['end_date']!==$old['end_date']){
            $rows=$db->query('SELECT id,reminder FROM followup WHERE master=2 AND master_id='.$id.' AND unfollow=0 AND park=0')->fetch_all(MYSQLI_ASSOC);
            foreach($rows as $f){ModuleFollowupService::cancelJobs($db,(int)$f['id']);$reminder=$next['end_date'].' '.substr($f['reminder'],11);$stmt=$db->prepare('UPDATE followup SET reminder=? WHERE id=?');$fid=(int)$f['id'];$stmt->bind_param('si',$reminder,$fid);$stmt->execute();$stmt->close();ModuleFollowupService::schedule($db,$fid);}
        }
        return ModuleFlowRuntime::response(0,'Package and change log saved','',['log_id'=>$logId]);
    }
    public static function park(array $r): array
    {
        $db=ModuleFlowRuntime::active()?ModuleFlowRuntime::db():(new dbconnection())->connect($_SESSION['db_name']??'ccc_database');$rows=$db->query('SELECT * FROM invoice WHERE '.ModuleFlowSql::clauses($r['d']??[]).' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        if(!$rows)throw new InvalidArgumentException('Select an invoice');$park=(int)($r['park_value']??1);if($park===3)$park=1;if(!in_array($park,[0,1],true))throw new InvalidArgumentException('Use cancellation or restore; financial history cannot be erased');
        foreach($rows as $row){
            $id=(int)$row['id'];$bid=(int)get_bid();$contact=(int)$row['contact_id'];if((int)$row['bid']!==$bid)throw new DomainException('Invoice is outside this branch');if((int)$row['park']===$park)continue;
            if(ModuleFlowRuntime::active()) ModuleFlowRuntime::lock('invoice-state:'.$id);
            if($park){
                $snapshot=['invoice_id'=>$id,'children'=>[]];foreach(['invoice_item','payment'] as $table){$snapshot['children'][$table]=$db->query("SELECT id,park FROM $table WHERE invoice_id=$id")->fetch_all(MYSQLI_ASSOC);$db->query("UPDATE $table SET park=1 WHERE invoice_id=$id");}
                $payload=json_encode($snapshot);$uid=(int)get_uid();$stmt=$db->prepare("INSERT INTO module_flow_outbox(actor_id,branch_id,channel,payload,state) VALUES(?,?,'INVOICE_STATE',?,'SNAPSHOT')");$stmt->bind_param('iis',$uid,$bid,$payload);$stmt->execute();$stmt->close();
                $followups=$db->query("SELECT f.id FROM followup f WHERE f.bid=$bid AND f.park=0 AND ((f.master=2 AND f.master_id IN(SELECT id FROM invoice_item WHERE invoice_id=$id)) OR (f.master=1 AND f.master_id IN(SELECT id FROM payment WHERE invoice_id=$id)))")->fetch_all(MYSQLI_ASSOC);
                foreach($followups as $f){$fid=(int)$f['id'];ModuleFollowupService::cancelJobs($db,$fid);$db->query("UPDATE followup SET park=1 WHERE id=$fid");}
                self::cancelDelivery($id);
            }else{
                $snapshot=$db->query("SELECT id,payload FROM module_flow_outbox WHERE channel='INVOICE_STATE' AND state='SNAPSHOT' AND JSON_EXTRACT(payload,'$.invoice_id')=$id ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetch_assoc();
                if(!$snapshot)throw new DomainException('This historical cancellation needs reconciliation before restore');
                $data=json_decode($snapshot['payload'],true);foreach($data['children'] as $table=>$children){if(!in_array($table,['invoice_item','payment'],true))throw new RuntimeException('Invalid state snapshot');foreach($children as $child)$db->query('UPDATE '.$table.' SET park='.(int)$child['park'].' WHERE id='.(int)$child['id'].' AND invoice_id='.$id);}
                $db->query("UPDATE module_flow_outbox SET state='RESTORED' WHERE id=".(int)$snapshot['id']);
            }
            $comment=(string)($r['comment']??'');$uid=(int)get_uid();$stmt=$db->prepare('UPDATE invoice SET park=?,invoice_comment=CONCAT(COALESCE(invoice_comment,\'\'),?),modified_by=? WHERE id=?');$stmt->bind_param('isii',$park,$comment,$uid,$id);$stmt->execute();$stmt->close();
            if((int)$row['quotation']===0){
                $active=(int)$db->query("SELECT COUNT(*) FROM invoice i JOIN invoice_item ii ON ii.invoice_id=i.id WHERE i.contact_id=$contact AND i.bid=$bid AND i.park=0 AND i.quotation=0 AND ii.park=0 AND ii.start_date<=CURDATE() AND ii.end_date>=CURDATE() AND (ii.sessions=0 OR ii.sessions_used<ii.sessions)")->fetch_row()[0];
                $db->query('UPDATE member SET status='.($active?1:0).' WHERE contact_id='.$contact.' AND bid='.$bid);
                if($park && $active===0){
                    $db->query("UPDATE inquiry SET converted=0,doc=NULL WHERE contact_id=$contact AND bid=$bid");
                    $activeFol=(int)$db->query("SELECT COUNT(*) FROM followup WHERE contact_id=$contact AND park=0 AND unfollow=0")->fetch_row()[0];
                    if($activeFol===0){
                        $lastInq=$db->query("SELECT id,bid,employee_id FROM inquiry WHERE contact_id=$contact AND bid=$bid AND park=0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
                        if($lastInq){
                            $inqId=(int)$lastInq['id'];$empId=(int)$lastInq['employee_id'];$tomorrow=date('Y-m-d 10:00:00',strtotime('+1 day'));$actor=(int)get_uid();
                            $db->query("INSERT INTO followup(contact_id,master,master_id,type,comment,reminder,employee_id,bid,status,unfollow,park,created_at,created_by) VALUES($contact,0,$inqId,0,'Invoice cancelled - follow up required','$tomorrow',$empId,$bid,1,0,0,NOW(),$actor)");
                        }
                    }
                }
            }
        }
        return ModuleFlowRuntime::response(0,$park?'Invoice cancelled':'Invoice restored; review reminder scheduling');
    }
    private static function cancelDelivery(int $id): void
    {
        $db=ModuleFlowRuntime::active()?ModuleFlowRuntime::db():(new dbconnection())->connect($_SESSION['db_name']??'ccc_database');$cid=(int)(ModuleFlowRuntime::$context['client_id']??$GLOBALS['ccc_authenticated_context']['client_id']??269);
        foreach(['invoice_invoiceitem_view'=>'invoice_item','invoice_payment_view'=>'payment'] as $view=>$table){$ids=array_map('intval',array_column($db->query("SELECT id FROM $table WHERE invoice_id=$id")->fetch_all(MYSQLI_ASSOC),'id'));if(!$ids)continue;$list=implode(',',$ids);foreach(['sms_ready','email_ready'] as $queue)$db->query("UPDATE `ccc_central`.`$queue` SET park=1 WHERE client_id=$cid AND table_name='$view' AND record_id IN($list)");$db->query("UPDATE `ccc_central`.`request` SET park=1 WHERE client_id=$cid AND table_name='$view' AND row_id IN($list)");}
    }
}
