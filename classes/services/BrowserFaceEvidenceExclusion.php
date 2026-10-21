<?php
/** CLI-only reversible label quarantine. Ciphertext and attendance are untouched. */
final class BrowserFaceEvidenceExclusion
{
    private static function ids(array $ids):string {
        if(!$ids || count($ids)>1000 || count($ids)!==count(array_unique($ids)))throw new InvalidArgumentException('Exact unique sample IDs required');
        foreach($ids as $id)if(!is_int($id)||$id<1)throw new InvalidArgumentException('Positive integer sample IDs required');
        sort($ids);return implode(',',$ids);
    }
    private static function snapshot(mysqli $db,array $ids):array {
        $list=self::ids($ids);$out=[];
        foreach(['face_web_calibration'=>'id','face_web_evidence'=>'calibration_id','face_web_enrollment'=>'calibration_id'] as $table=>$key){
            $out[$table]=$db->query("SELECT * FROM $table WHERE $key IN($list) ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
            foreach($out[$table] as &$row)unset($row['updated_at']);unset($row);
        }return $out;
    }
    private static function decoded(array $plan):array {
        $raw=base64_decode($plan['encrypted_records_snapshot'] ?? '',true);
        if($raw===false || !hash_equals($plan['snapshot_sha256'] ?? '',hash('sha256',$raw)))throw new InvalidArgumentException('Exclusion audit changed');
        $rows=unserialize($raw,['allowed_classes'=>false]);if(!is_array($rows))throw new InvalidArgumentException('Invalid snapshot');return $rows;
    }
    public static function prepare(mysqli $db,int $branch,int $retained,array $ids,string $reason):array {
        if($branch<1 || $retained<1 || trim($reason)==='' || strlen($reason)>1000)throw new InvalidArgumentException('Scope and operator-confirmed reason required');
        $db->begin_transaction();try{
            $keep=$db->prepare('SELECT id FROM member WHERE id=? AND bid=?');$keep->bind_param('ii',$retained,$branch);$keep->execute();if(!$keep->get_result()->num_rows)throw new InvalidArgumentException('Retained member scope mismatch');
            $rows=self::snapshot($db,$ids);
            if(count($rows['face_web_calibration'])!==count($ids))throw new InvalidArgumentException('Sample set changed');
            foreach($rows['face_web_calibration'] as $row){if((int)$row['branch_id']!==$branch || (int)$row['member_id']===$retained || $row['deleted_at']!==null)throw new InvalidArgumentException('Excluded sample belongs to retained identity, different branch, or is already excluded');}
            foreach($rows['face_web_enrollment'] as $row)if($row['state']==='ACTIVE' || $row['active_template_id']!==null)throw new InvalidArgumentException('Active records require separate authorization');
            $members=array_unique(array_column($rows['face_web_calibration'],'member_id'));$active=$db->query("SELECT id FROM face_template WHERE member_id IN(".self::ids(array_map('intval',$members)).") AND status='ACTIVE'");
            if($active->num_rows)throw new InvalidArgumentException('Active identity exclusion is not allowed');
            $database=$db->query('SELECT DATABASE()')->fetch_row()[0];$time=$db->query('SELECT NOW()')->fetch_row()[0];$raw=serialize($rows);$db->commit();
            return ['version'=>'browser-face-label-exclusion-v1','database'=>$database,'branch_id'=>$branch,'retained_member_id'=>$retained,'sample_ids'=>$ids,'reason'=>$reason,'exclusion_time'=>$time,'snapshot_sha256'=>hash('sha256',$raw),'encrypted_records_snapshot'=>base64_encode($raw),'affected_members'=>array_map('intval',$members),'prepared_only'=>true];
        }catch(Throwable $e){$db->rollback();throw $e;}
    }
    private static function expected(array $rows,array $plan):array {
        foreach($rows['face_web_calibration'] as &$row)$row['deleted_at']=$plan['exclusion_time'];unset($row);
        foreach($rows['face_web_evidence'] as &$row)$row['purpose']='LABEL_CONFLICT';unset($row);
        foreach($rows['face_web_enrollment'] as &$row){$row['state']='RECAPTURE_REQUIRED';$row['decision_reason']='LABEL_EXCLUDED';}unset($row);return $rows;
    }
    public static function change(mysqli $db,array $plan,bool $rollback=false):string {
        $database=$db->query('SELECT DATABASE()')->fetch_row()[0];if($database!==($plan['database'] ?? '') || ($plan['version'] ?? '')!=='browser-face-label-exclusion-v1')throw new InvalidArgumentException('Exclusion database/version mismatch');
        $before=self::decoded($plan);$after=self::expected($before,$plan);$list=self::ids($plan['sample_ids']);
        $lock='browser-face:'.substr(hash('sha256',$database),0,45);$s=$db->prepare('SELECT GET_LOCK(?,10)');$s->bind_param('s',$lock);$s->execute();if((int)$s->get_result()->fetch_row()[0]!==1)throw new RuntimeException('Face mutation busy');
        try{$db->begin_transaction();$current=self::snapshot($db,$plan['sample_ids']);
            $members=array_unique(array_column($before['face_web_calibration'],'member_id'));
            $scope=array_map('intval',array_unique(array_merge($members,[$plan['retained_member_id']])));$branch=(int)$plan['branch_id'];
            if((int)$db->query('SELECT COUNT(*) FROM member WHERE id IN('.self::ids($scope).') AND bid='.$branch)->fetch_row()[0]!==count($scope))throw new InvalidArgumentException('Member branch changed since audit');
            if($db->query("SELECT id FROM face_template WHERE member_id IN(".self::ids(array_map('intval',$members)).") AND status='ACTIVE'")->num_rows)throw new InvalidArgumentException('Active identity changed since audit');
            $target=$rollback?$before:$after;$source=$rollback?$after:$before;
            if($current===$target){$db->commit();return $rollback?'Already restored':'Already excluded';}
            if($current!==$source)throw new InvalidArgumentException('Records changed since audit; operation aborted');
            foreach($before['face_web_calibration'] as $row){$stamp=$rollback?$row['deleted_at']:$plan['exclusion_time'];$s=$db->prepare('UPDATE face_web_calibration SET deleted_at=? WHERE id=?');$id=(int)$row['id'];$s->bind_param('si',$stamp,$id);$s->execute();}
            foreach($before['face_web_evidence'] as $row){$purpose=$rollback?$row['purpose']:'LABEL_CONFLICT';$s=$db->prepare('UPDATE face_web_evidence SET purpose=? WHERE id=?');$id=(int)$row['id'];$s->bind_param('si',$purpose,$id);$s->execute();}
            foreach($before['face_web_enrollment'] as $row){$state=$rollback?$row['state']:'RECAPTURE_REQUIRED';$reason=$rollback?$row['decision_reason']:'LABEL_EXCLUDED';$id=(int)$row['id'];$s=$db->prepare('UPDATE face_web_enrollment SET state=?,decision_reason=? WHERE id=?');$s->bind_param('ssi',$state,$reason,$id);$s->execute();}
            if(self::snapshot($db,$plan['sample_ids'])!==$target)throw new RuntimeException('Exclusion verification failed');$db->commit();return $rollback?'Restored audited labels':'Excluded labels; encrypted records preserved';
        }catch(Throwable $e){$db->rollback();throw $e;}finally{$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();}
    }
}
