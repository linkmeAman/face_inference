<?php
final class AttendanceLedger
{
    public static function handle(string $action,array $r): array
    {
        $db=ModuleFlowRuntime::db();$filter=$r[in_array($action,['park','delete'],true)?'d':'filter']??[];
        $rows=$db->query('SELECT * FROM attendance WHERE '.ModuleFlowSql::clauses($filter).' ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        $changes=[];foreach($r['u']??[] as $field){if(!in_array($field['col']??'',['negative','comment','in_time','out_time'],true))throw new InvalidArgumentException('Attendance identity/date/session cannot be changed by a generic edit');$changes[$field['col']]=$field['val'];}
        $result=[];
        foreach($rows as $row){
            self::datePolicy($row['date']);
            if((int)$row['bid']!==(int)get_bid())throw new DomainException('Attendance belongs to another branch');
            $park=in_array($action,['park','delete'],true)?(int)($r['park_value']??1):(int)$row['park'];
            if(!in_array($park,[0,1,2],true))throw new InvalidArgumentException('Invalid attendance state');
            $negative=array_key_exists('negative',$changes)?(int)$changes['negative']:(int)$row['negative'];
            if(!in_array($negative,[0,1,2],true))throw new InvalidArgumentException('Invalid attendance status');
            $comment=array_key_exists('comment',$changes)?(string)$changes['comment']:(string)$row['comment'];
            if(in_array($action,['park','delete'],true)&&!empty($r['comment']))$comment=trim($comment.' '.$r['comment']);
            self::transition($db,$row,$park,$negative,$comment);
            foreach(['in_time','out_time'] as $time)if(isset($changes[$time])){
                if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D',(string)$changes[$time]))throw new InvalidArgumentException('Invalid attendance time');
                $id=(int)$row['id'];$value=$changes[$time];$stmt=$db->prepare('UPDATE attendance SET '.$time.'=? WHERE id=?');$stmt->bind_param('si',$value,$id);$stmt->execute();$stmt->close();
            }
            $result[]=['attendance_id'=>(int)$row['id'],'state'=>$park===0?'ACTIVE':'REVERSED'];
        }
        return ModuleFlowRuntime::response(0,'Attendance '.($action==='update'?'updated':((int)($r['park_value']??1)===0?'restored':'deleted')),'',['results'=>$result]);
    }
    public static function datePolicy(string $date): void
    {
        if(substr($date,0,7)!==date('Y-m') && !(new session())->can_edit_previous_month(get_uid()))throw new DomainException('Previous-month attendance is not allowed');
    }
    public static function restore(mysqli $db,int $id,string $prefix=''): array
    {
        $table=$prefix.'attendance';if(!in_array($prefix,['','face_testing_'],true))throw new InvalidArgumentException('Invalid attendance table prefix');
        $row=$db->query('SELECT * FROM `'.$table.'` WHERE id='.$id.' FOR UPDATE')->fetch_assoc();
        if(!$row)throw new DomainException('The original attendance is missing');
        self::transition($db,$row,0,(int)$row['negative'],(string)$row['comment'],$prefix);
        return $db->query('SELECT * FROM `'.$table.'` WHERE id='.$id)->fetch_assoc();
    }
    public static function transition(mysqli $db,array $row,int $park,int $negative,string $comment,string $prefix=''): void
    {
        $attTable=$prefix.'attendance';$eventTable=$prefix.'attendance_event';
        $id=(int)$row['id'];$event=$db->query("SELECT id,metadata FROM `$eventTable` WHERE attendance_id=$id AND event_type IN('PUNCH_IN','ATTENDANCE_FINALIZED') ORDER BY id LIMIT 1 FOR UPDATE")->fetch_assoc();
        $meta=$event?json_decode($event['metadata']??'{}',true):[];$meta=is_array($meta)?$meta:[];
        $effect=$meta['package_effect']??null;
        if($row['type']==='M' && !$effect){
            $effect=self::historicalEffect($db,$row);
            if(!$event){
                $source='MANUAL';$key='legacy-ledger:'.$id;$bid=(int)$row['bid'];$batch=(int)$row['batch_id'];$sid=(int)$row['session_id'];$contact=(int)$row['contact_id'];$actor=(int)get_uid();$date=$row['date'];
                $stmt=$db->prepare("INSERT INTO `$eventTable`(branch_id,batch_id,session_id,attendance_date,person_type,person_id,event_type,source,actor_employee_id,attendance_id,client_attempt_id,occurred_at_server) VALUES(?,?,?,?,'M',?,'PUNCH_IN',?,?,?, ?,NOW())");$stmt->bind_param('iiisisiis',$bid,$batch,$sid,$date,$contact,$source,$actor,$id,$key);$stmt->execute();$event=['id'=>$db->insert_id];$stmt->close();
            }
        }
        if($effect){
            $item=(int)$effect['item_id'];$oldApplied=!empty($effect['applied'])?(int)$effect['deducted']:0;
            $desired=$negative===0?1:($negative===1?(int)($effect['absence_deducts']??0):0);
            if($item===0 && $desired>0)throw new DomainException('This observation has no package; select an eligible package through attendance');
            if($item){
                $package=$db->query('SELECT sessions,sessions_used,park FROM invoice_item WHERE id='.$item.' FOR UPDATE')->fetch_assoc();
                if(!$package)throw new DomainException('The original charged package is missing');
                if((int)$package['sessions']===0)$desired=0;
                $newApplied=$park===0?$desired:0;$delta=$newApplied-$oldApplied;$used=(int)$package['sessions_used']+$delta;
                if($used<0 || ((int)$package['sessions']>0&&$used>(int)$package['sessions']))throw new DomainException('This correction requires package balance reconciliation');
                if($park===0 && (int)$package['park']!==0)throw new DomainException('The original package is inactive');
                $db->query('UPDATE invoice_item SET sessions_used='.$used.',modified_by='.(int)get_uid().' WHERE id='.$item);
            }else{$newApplied=0;}
            $effect['deducted']=$desired;$effect['applied']=$park===0;
            $meta['package_effect']=$effect;
            $session=$park===0?$newApplied:($row['session']??0);
        }else{$session=(int)$row['session'];}
        if((int)$row['park']!==$park || (int)$row['negative']!==$negative){
            $meta['corrections'][]=['actor_id'=>(int)get_uid(),'at'=>date('c'),'from'=>['park'=>(int)$row['park'],'negative'=>(int)$row['negative']],'to'=>['park'=>$park,'negative'=>$negative]];
            if((int)$row['makeup']===1 && $row['type']==='M' && (((int)$row['park']===0)!==($park===0))){$delta=$park===0?-1:1;$db->query('UPDATE member SET pending_makeup=GREATEST(0,pending_makeup+('.$delta.')) WHERE contact_id='.(int)$row['contact_id']);}
        }
        $actor=(int)get_uid();$stmt=$db->prepare('UPDATE `'.$attTable.'` SET park=?,negative=?,session=?,comment=?,modified_by=? WHERE id=?');$stmt->bind_param('iiisii',$park,$negative,$session,$comment,$actor,$id);$stmt->execute();$stmt->close();
        if($event){$json=json_encode($meta);$eid=(int)$event['id'];$stmt=$db->prepare('UPDATE `'.$eventTable.'` SET metadata=? WHERE id=?');$stmt->bind_param('si',$json,$eid);$stmt->execute();$stmt->close();}
    }
    private static function historicalEffect(mysqli $db,array $row): array
    {
        $contact=(int)$row['contact_id'];$batch=(int)$row['batch_id'];$date=$db->real_escape_string($row['date']);
        $items=$db->query("SELECT ii.id,ii.sessions FROM invoice_item ii JOIN invoice i ON i.id=ii.invoice_id WHERE i.contact_id=$contact AND ii.batch_id IN($batch,COALESCE((SELECT parent_id FROM batch WHERE id=$batch),0)) AND ii.start_date<='$date' AND ii.end_date>='$date' AND i.quotation=0")->fetch_all(MYSQLI_ASSOC);
        if(count($items)!==1)throw new DomainException('Historical attendance has no unique package link; reconcile it before correction');
        $setting=$db->query('SELECT deduct_session_absentee FROM attendance_settings WHERE bid='.(int)$row['bid'])->fetch_assoc();
        return ['item_id'=>(int)$items[0]['id'],'deducted'=>(int)$items[0]['sessions']===0?0:(int)$row['session'],'absence_deducts'=>(int)($setting['deduct_session_absentee']??0),'applied'=>(int)$row['park']===0,'historical_inferred'=>true];
    }
}
