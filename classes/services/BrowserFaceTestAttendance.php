<?php
require_once __DIR__.'/BrowserFacePilot.php';
require_once __DIR__.'/AttendanceDomainService.php';

/** Uses the existing isolated Practice database. No production attendance connection. */
final class BrowserFaceTestAttendance
{
    private mysqli $source;
    private mysqli $isolated;
    public function __construct(mysqli $source, mysqli $isolated, array $config)
    {
        BrowserFacePilot::verify($isolated,$config);
        $isolated->query('SELECT id FROM face_testing_attendance LIMIT 0');
        $isolated->query('SELECT id FROM face_testing_attendance_event LIMIT 0');
        $this->source=$source;$this->isolated=$isolated;
    }
    public static function connect(mysqli $source): self
    {
        $config=BrowserFacePilot::load('ccc_database',2);
        $db=(new dbconnection)->connect($config['database']);
        try{return new self($source,$db,$config);}catch(Throwable $e){$db->close();throw $e;}
    }
    private function rows(mysqli $db,string $sql,array $args=[]): array
    {
        $s=$db->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);
        $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
    }
    public function status(array $context,array $people): array
    {
        $allowed=array_column($people,null,'contact_id');$result=[];
        foreach($this->rows($this->isolated,"SELECT id,contact_id,negative FROM face_testing_attendance WHERE bid=2 AND batch_id=? AND session_id=? AND date=? AND type='M' AND park=0",[$context['batch_id'],$context['session_id'],$context['attendance_date']]) as $r){
            if(isset($allowed[$r['contact_id']]) && (int)$r['negative']===0)$result[]=['person_id'=>(int)$allowed[$r['contact_id']]['id'],'contact_id'=>(int)$r['contact_id'],'test_attendance_id'=>(int)$r['id'],'state'=>'PRESENT_PUNCHED_TEST'];
        }return $result;
    }
    public function record(array $context,array $people,int $actor,string $key): array
    {
        // Shadows of domain read/membership tables are connection-local and
        // disappear on close. The original Practice 623 rows are never changed.
        foreach(['batch'=>"id INT PRIMARY KEY,bid INT,venue_id INT,batch VARCHAR(255),completed_sessions INT,parent_id INT,start_date DATE,end_date DATE,park INT",'batch_time'=>"id INT PRIMARY KEY,batch_id INT,day_code INT,start_time TIME,end_time TIME,park INT",'attendance_settings'=>"bid INT PRIMARY KEY,deduct_session_absentee INT",'member'=>"id INT PRIMARY KEY,contact_id INT,bid INT,status INT,park INT",'invoice_item'=>"id INT PRIMARY KEY,sessions INT,sessions_used INT,modified_by INT"] as $table=>$columns){
            $this->isolated->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
            $this->isolated->query("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
        }
        $this->isolated->query('DROP TEMPORARY TABLE IF EXISTS invoice_invoiceitem_view');
        $this->isolated->query('CREATE TEMPORARY TABLE invoice_invoiceitem_view(item_id INT,contact_id INT,batch_id INT,start_date DATE,end_date DATE,sessions INT,sessions_used INT,bid INT,quotation INT,park INT,item_park INT) ENGINE=InnoDB');
        $batch=$this->rows($this->source,'SELECT id,bid,venue_id,batch,completed_sessions,parent_id,start_date,end_date,park FROM batch WHERE id=? AND bid=2 AND park=0',[$context['batch_id']])[0] ?? null;
        if(!$batch)throw new InvalidArgumentException('Testing batch is unavailable');
        $this->insert('batch',$batch);
        foreach($this->rows($this->source,'SELECT id,batch_id,day_code,start_time,end_time,park FROM batch_time WHERE batch_id=? AND park=0',[$context['batch_id']]) as $bt){
            $this->insert('batch_time',$bt);
        }
        $attSettings=$this->rows($this->source,'SELECT bid,deduct_session_absentee FROM attendance_settings WHERE bid=?',[2])[0] ?? ['bid'=>2,'deduct_session_absentee'=>0];
        $this->insert('attendance_settings',$attSettings);
        $results=[];
        foreach($people as $p){
            $member=$this->rows($this->source,'SELECT id,contact_id,bid,status,park FROM member WHERE id=? AND bid=2 AND status=1 AND park=0',[$p['id']])[0] ?? null;
            $membership=$this->rows($this->source,'SELECT item_id,start_date,end_date,batch_id FROM invoice_invoiceitem_module_view WHERE contact_id=? AND batch_id=? AND park=0 AND item_park=0 AND start_date<=? AND end_date>=? AND (sessions=0 OR sessions>sessions_used) ORDER BY start_date,item_id LIMIT 1',[$p['contact_id'],$context['batch_id'],$context['attendance_date'],$context['attendance_date']])[0] ?? null;
            if(!$member || !$membership){$results[]=['person_id'=>(int)$p['id'],'contact_id'=>(int)$p['contact_id'],'state'=>'REJECTED','reason'=>'INELIGIBLE'];continue;}
            $this->insert('member',$member);
            $item=$this->rows($this->source,'SELECT id,sessions,sessions_used,modified_by FROM invoice_item WHERE id=?',[$membership['item_id']])[0];
            // Unlimited zero-value sandbox membership, never real deductions.
            $item['sessions']=0;$item['sessions_used']=0;
            foreach(['item_amount','item_total'] as $field)if(isset($item[$field]))$item[$field]=0;
            $this->insert('invoice_item',$item);
            $this->insert('invoice_invoiceitem_view',$membership+['contact_id'=>$p['contact_id'],'sessions'=>0,'sessions_used'=>0,'bid'=>2,'quotation'=>0,'park'=>0,'item_park'=>0]);
            $r=(new AttendanceDomainService($this->isolated,'face_testing_'))->recordMemberAttendance([
                'branch_id'=>2,'batch_id'=>(int)$context['batch_id'],'attendance_date'=>$context['attendance_date'],'session_id'=>(int)$context['session_id'],
                'member_id'=>(int)$p['contact_id'],'person_type'=>'M','negative'=>0,'source'=>'TEACHER_CONFIRMED','event_type'=>'PUNCH_IN','actor_employee_id'=>$actor,
                'client_attempt_id'=>'test_punch_'.hash('sha256',$key.':'.$p['id']),'check_month'=>true,
                'metadata'=>['test_only'=>true,'biometric_acceptance'=>false,'production_validated'=>false,'member_id'=>(int)$p['id'],'recognition_receipt'=>$p['receipt_key'],'punch_request'=>$key]]);
            $results[]=['person_id'=>(int)$p['id'],'contact_id'=>(int)$p['contact_id'],'person_name'=>$p['name'],'state'=>$r['state'],'test_attendance_id'=>(int)($r['attendance_id'] ?? 0),'deducted_sessions'=>(int)($r['deducted_sessions'] ?? 0),'reason'=>$r['reason'] ?? ''];
        }return $results;
    }
    private function insert(string $table,array $row): void
    {
        $fields=array_keys($row);$s=$this->isolated->prepare('INSERT IGNORE INTO `'.$table.'` (`'.implode('`,`',$fields).'`) VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        $values=array_values($row);$s->bind_param(str_repeat('s',count($values)),...$values);$s->execute();$s->close();
    }
    public function __destruct(){$this->isolated->close();}
}
