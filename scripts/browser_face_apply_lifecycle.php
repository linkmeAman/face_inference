<?php
// Reviewed additive deployment plus recovery of previously journaled captures.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/classes/dbconnection.class.php';
require_once dirname(__DIR__).'/classes/services/BrowserFacePolicy.php';
$backup=$argv[1] ?? '';
if(!is_file($backup) || filesize($backup)<1000 || time()-filemtime($backup)>3600 || (fileperms($backup)&0007)!==0)throw new RuntimeException('Fresh private tenant backup required');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=(new dbconnection)->connect('ccc_database');
$db->multi_query(file_get_contents(dirname(__DIR__).'/database/migrations/20261009_03_browser_face_lifecycle.sql'));while($db->more_results())$db->next_result();
$contract=BrowserFacePolicy::contract();$lock='browser-face:'.substr(hash('sha256','ccc_database'),0,45);$s=$db->prepare('SELECT GET_LOCK(?,10)');$s->bind_param('s',$lock);$s->execute();if((int)$s->get_result()->fetch_row()[0]!==1)throw new RuntimeException('Face mutation busy');
try{$db->begin_transaction();
    // Only an enrollment journal can establish these were enrollment captures.
    // Standalone calibration samples do not become enrollment candidates.
    $sql="INSERT IGNORE INTO face_web_enrollment(branch_id,member_id,actor_id,state,encrypted_template,encryption_key_id,model_version,preprocessing_version,calibration_id,capture_session,consented_at,expires_at,decision_reason)
          SELECT c.branch_id,c.member_id,c.actor_id,'PENDING',c.encrypted_embedding,c.encryption_key_id,c.model_version,c.preprocessing_version,c.id,CONCAT('legacy_capture_',c.id,'_journal'),c.consented_at,c.expires_at,'RECOVERED_ENROLLMENT_CAPTURE'
          FROM face_web_calibration c JOIN member m ON m.id=c.member_id
          WHERE c.branch_id=2 AND m.bid=2 AND m.status=1 AND m.park=0 AND c.deleted_at IS NULL AND c.expires_at>NOW()
          AND c.model_version=? AND c.preprocessing_version=? AND LENGTH(c.encrypted_embedding)>0
          AND EXISTS(SELECT 1 FROM face_web_request r WHERE r.actor_id=c.actor_id AND r.action='enroll' AND JSON_UNQUOTE(JSON_EXTRACT(r.response,'$.state'))='ENROLLMENT_PENDING' AND JSON_EXTRACT(r.response,'$.sample_id')=c.id AND JSON_EXTRACT(r.response,'$.person_id')=c.member_id)
          ORDER BY c.id DESC";
    $s=$db->prepare($sql);$s->bind_param('ss',$contract['model_version'],$contract['preprocessing_version']);$s->execute();$count=$s->affected_rows;$db->commit();
    echo json_encode(['database'=>'ccc_database','collection_schema_installed'=>true,'recovered_pending_members'=>$count,'activated_templates'=>0,'attendance_changed'=>false])."\n";
}catch(Throwable $e){$db->rollback();throw $e;}finally{$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();}
