<?php
final class ModuleBatchPolicy
{
    public static function validate(string $action,array &$r): void
    {
        $db=ModuleFlowRuntime::db();$bid=(int)get_bid();$current=null;$id=0;
        if($action==='update'){
            $rows=$db->query('SELECT * FROM batch WHERE '.ModuleFlowSql::clauses($r['filter']??[]).' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
            if(count($rows)!==1)throw new InvalidArgumentException('Edit exactly one batch at a time');$current=$rows[0];$id=(int)$current['id'];
            $input=$current;foreach($r['u']??[] as $v)$input[$v['col']]=$v['val'];
            if(!array_key_exists('contact_id',$input))$input['contact_id']=array_column($db->query('SELECT contact_id FROM batch_employee WHERE batch_id='.$id.' AND park=0')->fetch_all(MYSQLI_ASSOC),'contact_id');
            if(!array_key_exists('batch_time',$input))$input['batch_time']=$db->query('SELECT day_code,start_time,end_time FROM batch_time WHERE batch_id='.$id.' AND park=0')->fetch_all(MYSQLI_ASSOC);
        }else{$input=$r;}
        $cat=(int)($input['category_id']??0);
        if(!$db->query('SELECT id FROM category WHERE id='.$cat.' AND bid='.$bid.' AND park=0')->num_rows)throw new DomainException('Choose an active category in this branch');
        $trainers=$input['contact_id']??[];$times=$input['batch_time']??[];
        if(!is_array($trainers)||!is_array($times)||(!$trainers||!$times)&&empty($input['training_assign']))throw new InvalidArgumentException('Add at least one trainer and one timing');
        foreach($trainers as $contact){
            if(!ctype_digit((string)$contact)||(int)$contact<1)throw new InvalidArgumentException('Invalid trainer');
            $c=(int)$contact;
            if(!$db->query('SELECT e.id FROM employee e JOIN employee_bid eb ON eb.employee_id=e.id WHERE e.contact_id='.$c.' AND eb.bid='.$bid.' AND e.status=1 AND e.park=0 AND e.position_id IN(1,5,17,18,19,44)')->num_rows)throw new DomainException('The selected trainer is not eligible in this branch');
        }
        $seen=[];
        foreach($times as $time){
            $day=(string)($time['day_code']??'');$start=(string)($time['start_time']??'');$end=(string)($time['end_time']??'');
            if(!preg_match('/^[0-6]$/D',$day)||!preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D',$start)||!preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D',$end)||$end<=$start)throw new InvalidArgumentException('Each class must have a valid weekday and an end time after its start');
            foreach($seen as $t)if($t['day_code']===$day&&$t['start_time']<$end&&$t['end_time']>$start)throw new InvalidArgumentException('Class timings overlap');
            $seen[]=['day_code'=>$day,'start_time'=>$start,'end_time'=>$end];
        }
        foreach(['start_date','end_date'] as $field){
            if($field==='end_date'&&empty($input[$field]))continue;
            $d=DateTime::createFromFormat('!Y-m-d',(string)($input[$field]??''));if(!$d||$d->format('Y-m-d')!==$input[$field])throw new InvalidArgumentException('Invalid batch '.$field);
        }
        if(!empty($input['end_date'])&&$input['end_date']<$input['start_date'])throw new InvalidArgumentException('Batch end date precedes start date');
        if((int)($input['limit']??0)<0)throw new InvalidArgumentException('Capacity cannot be negative');
        if($current && (int)($input['limit']??0)>0){
            $used=(int)$db->query('SELECT COUNT(DISTINCT i.contact_id) FROM invoice i JOIN invoice_item ii ON ii.invoice_id=i.id JOIN member m ON m.contact_id=i.contact_id WHERE ii.batch_id='.$id.' AND i.park=0 AND i.quotation=0 AND ii.park=0 AND m.park=0 AND m.status=1 AND ii.end_date>=CURDATE()')->fetch_row()[0];
            if($used>(int)$input['limit'])throw new DomainException('Capacity cannot be lower than the active enrollment');
        }
        if(!empty($input['venue_id'])&&!$db->query('SELECT id FROM venue WHERE id='.(int)$input['venue_id'].' AND park=0')->num_rows)throw new DomainException('Venue is unavailable');
        ModuleFlowRuntime::lock('batch-schedule');
        $setting=$db->query('SELECT check_emp_dup FROM batch_settings WHERE bid='.$bid)->fetch_assoc();
        if(!empty($setting['check_emp_dup'])&&$trainers){
            $contacts=implode(',',array_map('intval',$trainers));$from=$db->real_escape_string($input['start_date']);$to=$db->real_escape_string($input['end_date']?:'2099-12-31');
            foreach($times as $time){$day=(int)$time['day_code'];$start=$db->real_escape_string($time['start_time']);$end=$db->real_escape_string($time['end_time']);
                if($db->query("SELECT b.id FROM batch b JOIN batch_employee be ON be.batch_id=b.id JOIN batch_time bt ON bt.batch_id=b.id WHERE b.id<>$id AND b.bid=$bid AND b.park=0 AND be.park=0 AND bt.park=0 AND be.contact_id IN($contacts) AND b.start_date<='$to' AND b.end_date>='$from' AND bt.day_code=$day AND bt.start_time<'$end' AND bt.end_time>'$start' LIMIT 1")->num_rows)throw new DomainException('Trainer already has a class during this time');
            }
        }
        if($current && $times){
            $masters=$db->query('SELECT id FROM session_master_view WHERE category_id='.$cat)->num_rows;
            if($masters){require_once dirname(__DIR__,2).'/controllers/batch_session.php';$dates=(new batch_session())->getDateOccurenceArr($db,$input['start_date'],$input['end_date'],$times,$masters);if(count($dates)!==$masters)throw new InvalidArgumentException('Master sessions do not match the edited timetable');}
        }
        if($current && !array_filter($r['u'],fn($v)=>in_array($v['col'],['batch','category_id','start_date','end_date','limit','completed_sessions','level_id','on_web'],true)))$r['u'][]=['col'=>'batch','val'=>$current['batch']];
    }
    public static function cancel(array $r): void
    {
        $db=ModuleFlowRuntime::db();$batch=(int)($r['batch_id']??0);$bid=(int)get_bid();$date=(string)($r['date']??'');
        $d=DateTime::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Invalid class date');
        AttendanceLedger::datePolicy($date);
        ModuleFlowRuntime::lock('class:'.$batch.':'.$date);
        if(!$db->query('SELECT id FROM batch WHERE id='.$batch.' AND bid='.$bid.' AND park=0 FOR UPDATE')->num_rows)throw new DomainException('Class is not available in this branch');
        if(in_array((int)($r['status']??0),[2,3],true)){
            $stmt=$db->prepare('SELECT id FROM attendance WHERE batch_id=? AND date=? AND park=0 AND status=1 AND contact_id>0 LIMIT 1');$stmt->bind_param('is',$batch,$date);$stmt->execute();$done=$stmt->get_result()->num_rows;$stmt->close();
            if($done)throw new DomainException('A completed class requires an administrative correction before cancellation');
        }
    }
}
