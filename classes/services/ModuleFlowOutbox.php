<?php
final class ModuleFlowOutbox
{
    public static function enqueue(string $channel,array $payload): int
    {
        $db=ModuleFlowRuntime::db();$uid=(int)get_uid();$bid=(int)get_bid();$json=json_encode($payload,JSON_THROW_ON_ERROR);
        $stmt=$db->prepare('INSERT INTO module_flow_outbox(actor_id,branch_id,channel,payload) VALUES(?,?,?,?)');$stmt->bind_param('iiss',$uid,$bid,$channel,$json);$stmt->execute();$id=(int)$db->insert_id;$stmt->close();return $id;
    }
    /** Called only by the existing CLI delivery workers, after business commits. */
    public static function dispatch(string $channel,bool $deliver=false,?string $onlyDatabase=null): array
    {
        $central=(new dbconnection())->connect('ccc_central');$summary=[];
        $filter=$onlyDatabase===null?'':" AND db_name='".$central->real_escape_string($onlyDatabase)."'";
        $tenants=$central->query("SELECT id,db_name FROM client_db WHERE inactive=0 AND db_name<>''".$filter)->fetch_all(MYSQLI_ASSOC);
        foreach($tenants as $tenant){
            $db=null;$lock='';
            try{
                $db=(new dbconnection())->connect($tenant['db_name']);
                if(!$db->query("SHOW TABLES LIKE 'module_flow_outbox'")->num_rows)continue;
                $stmt=$db->prepare('SELECT COUNT(*) FROM module_flow_outbox WHERE channel=? AND state=\'PENDING\' AND available_at<=NOW()');$stmt->bind_param('s',$channel);$stmt->execute();$pending=(int)$stmt->get_result()->fetch_row()[0];$stmt->close();
                $stmt=$db->prepare("SELECT id,state,attempts,created_at FROM module_flow_outbox WHERE channel=? AND state IN('PROCESSING','REVIEW') ORDER BY id");$stmt->bind_param('s',$channel);$stmt->execute();$reviews=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$summary[$tenant['db_name']]=['pending'=>$pending,'review'=>$reviews];if(!$deliver)continue;
                $physical=$db->query('SELECT DATABASE()')->fetch_row()[0];$lock='ccc_outbox_'.substr(hash('sha256',$physical.':'.$channel),0,46);$stmt=$db->prepare('SELECT GET_LOCK(?,0)');$stmt->bind_param('s',$lock);$stmt->execute();$acquired=(int)$stmt->get_result()->fetch_row()[0];$stmt->close();if(!$acquired)continue;
                $stmt=$db->prepare("SELECT * FROM module_flow_outbox WHERE channel=? AND state='PENDING' AND available_at<=NOW() ORDER BY id LIMIT 25");$stmt->bind_param('s',$channel);$stmt->execute();$jobs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
                foreach($jobs as $job){
                    $id=(int)$job['id'];$uid=(int)$job['actor_id'];$bid=(int)$job['branch_id'];$cid=(int)$tenant['id'];
                    $db->query("UPDATE module_flow_outbox SET state='PROCESSING',attempts=attempts+1 WHERE id=$id AND state='PENDING'");if(!$db->affected_rows)continue;
                    $actor=$central->query("SELECT id FROM user WHERE id=$uid AND client_id=$cid AND park=0 AND inactive=0")->fetch_assoc();
                    if(!$actor){$db->query("UPDATE module_flow_outbox SET state='CANCELLED' WHERE id=$id");continue;}
                    $GLOBALS['ccc_authenticated_context']=['id'=>$uid,'bid'=>$bid,'client_id'=>$cid,'db_name'=>$tenant['db_name']];
                    $p=json_decode($job['payload'],true,512,JSON_THROW_ON_ERROR);
                    if(!empty($p['table'])){
                        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$p['table']))throw new RuntimeException('Invalid outbox resource');$rid=(int)$p['id'];$key=str_contains($p['table'],'invoiceitem')?'item_id':'id';
                        $row=$db->query('SELECT * FROM `'.$p['table'].'` WHERE '.$key.'='.$rid.' LIMIT 1')->fetch_assoc();
                        if(!$row || !empty($row['park']) || !empty($row['item_park']) || !empty($row['unfollow'])){$db->query("UPDATE module_flow_outbox SET state='CANCELLED' WHERE id=$id");continue;}
                    }
                    try{
                        if($channel==='SMS'){$service=new sms_via_module();$delivery=$service->send($p['table'],$p['id'],$db,$p['request_type'],$p['recipient'],$p['body_id'],$p['user_type']);}
                        elseif($channel==='EMAIL'){$service=new email_via_module();$delivery=$service->send($p['table'],$p['id'],$db,$p['request_type'],$p['recipient'],$p['cc'],$p['studio'],$p['request']);}
                        else throw new RuntimeException('Unsupported outbox channel');
                        $state=in_array($delivery,['DELIVERED','SKIPPED','REVIEW'],true)?$delivery:'REVIEW';
                        $db->query("UPDATE module_flow_outbox SET state='$state',delivered_at=".($state==='DELIVERED'?'NOW()':'NULL')." WHERE id=$id");
                    }catch(Throwable $e){$db->query("UPDATE module_flow_outbox SET state='REVIEW' WHERE id=$id");error_log('Outbox delivery needs review: job '.$id);}
                }
            }catch(Throwable $e){error_log('Outbox tenant unavailable: '.$tenant['db_name']);}
            finally{unset($GLOBALS['ccc_authenticated_context']);if($db){if($lock){$stmt=$db->prepare('SELECT RELEASE_LOCK(?)');$stmt->bind_param('s',$lock);$stmt->execute();$stmt->close();}$db->close();}}
        }
        $central->close();return $summary;
    }
}
