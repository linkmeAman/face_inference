<?php
require_once __DIR__.'/AttendanceDomainService.php';
final class ModuleAttendanceEntry
{
    public static function record(array $r): array
    {
        $db=ModuleFlowRuntime::db();$bid=(int)get_bid();$contact=(int)($r['contact_id']??0);$code=(string)($r['code']??'');$batch=(int)($r['batch_id']??0);
        $stamp=(string)($r['datetime']??date('Y-m-d H:i:s'));$d=DateTime::createFromFormat('!Y-m-d H:i:s',$stamp);if(!$d||$d->format('Y-m-d H:i:s')!==$stamp)throw new InvalidArgumentException('Choose a valid attendance date and time');$date=substr($stamp,0,10);$time=substr($stamp,11);
        AttendanceLedger::datePolicy($date);
        $stmt=$db->prepare("SELECT e.contact_id FROM employee e JOIN employee_bid eb ON eb.employee_id=e.id WHERE (e.contact_id=? OR (?=0 AND e.ecode=?)) AND eb.bid=? AND e.park=0 AND e.status=1");$stmt->bind_param('iisi',$contact,$contact,$code,$bid);$stmt->execute();$employee=$stmt->get_result()->fetch_assoc();$stmt->close();$type=$employee?'E':'M';
        if($employee)$contact=(int)$employee['contact_id'];
        else{$stmt=$db->prepare('SELECT contact_id FROM member WHERE (contact_id=? OR (?=0 AND code=?)) AND bid=? AND park=0 AND status=1');$stmt->bind_param('iisi',$contact,$contact,$code,$bid);$stmt->execute();$member=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$member)throw new DomainException('Choose an active member or employee in this branch');$contact=(int)$member['contact_id'];}
        if(!$batch && $type==='M'){
            $stmt=$db->prepare('SELECT DISTINCT ii.batch_id FROM invoice_item ii JOIN invoice i ON i.id=ii.invoice_id WHERE i.contact_id=? AND i.bid=? AND i.quotation=0 AND i.park=0 AND ii.park=0 AND ii.batch_id>0 AND ii.start_date<=? AND ii.end_date>=? AND (ii.sessions=0 OR ii.sessions_used<ii.sessions)');$stmt->bind_param('iiss',$contact,$bid,$date,$date);$stmt->execute();$batches=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();if(count($batches)!==1)throw new DomainException('Select the batch for this member attendance');$batch=(int)$batches[0]['batch_id'];
        }
        if(!$batch)return self::employeeTime($contact,$date,$time,$r);
        $session=(int)($r['attendance_session_id']??$r['s_id']??0);$event=strtoupper((string)($r['event_type']??'PUNCH_IN'));$attempt=(string)($r['client_attempt_id']??'legacy:'.$contact.':'.$batch.':'.$date.':'.$session.':'.$event);
        $result=(new AttendanceDomainService($db))->recordMemberAttendance(['branch_id'=>$bid,'batch_id'=>$batch,'attendance_date'=>$date,'session_id'=>$session,'member_id'=>$contact,'person_type'=>$type,'negative'=>(int)($r['negative']??0),'source'=>'MANUAL','event_type'=>$event,'actor_employee_id'=>(int)get_uid(),'client_attempt_id'=>$attempt,'attendance_time'=>$time,'metadata'=>['entry'=>'legacy_compatible']]);
        return ModuleFlowRuntime::response(in_array($result['state'],['RECORDED','PUNCH_OUT_RECORDED','ALREADY_MARKED'],true)?0:1,$result['message']??$result['state'],$result['reason']??'',$result);
    }
    private static function employeeTime(int $contact,string $date,string $time,array $r): array
    {
        $db=ModuleFlowRuntime::db();$bid=(int)get_bid();$uid=(int)get_uid();
        if($contact!==(int)ModuleFlowRuntime::$context['contact_id'] && !(new session())->access($uid,(new dbconnection())->module('Employee Attendance'),2) && !(new session())->access($uid,(new dbconnection())->module('Employee'),3))throw new DomainException('Employee attendance administration is required for another employee');
        ModuleFlowRuntime::lock('employee-time:'.$contact.':'.$date);
        $row=$db->query("SELECT id,in_time,out_time FROM attendance WHERE contact_id=$contact AND bid=$bid AND date=".ModuleFlowSql::value($date)." AND batch_id=0 AND type='E' AND park=0 ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetch_assoc();
        $event=strtoupper((string)($r['event_type']??'PUNCH_IN'));
        if($event==='PUNCH_OUT'){
            if(!$row)throw new DomainException('Punch in before punching out');if($time<$row['in_time'])throw new InvalidArgumentException('Punch out must follow punch in');$id=(int)$row['id'];if($row['out_time']==='00:00:00')$db->query('UPDATE attendance SET out_time='.ModuleFlowSql::value($time).',modified_by='.$uid.' WHERE id='.$id);
        }elseif(!$row){$stmt=$db->prepare("INSERT INTO attendance(contact_id,bid,date,in_time,batch_id,type,status,negative,session,created_by) VALUES(?,?,?,?,0,'E',1,0,0,?)");$stmt->bind_param('iissi',$contact,$bid,$date,$time,$uid);$stmt->execute();$id=(int)$db->insert_id;$stmt->close();}else $id=(int)$row['id'];
        return ModuleFlowRuntime::response(0,'Employee attendance saved','',['attendance_id'=>$id]);
    }
}
