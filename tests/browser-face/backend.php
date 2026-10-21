<?php
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
// Connection-scoped temporary fixtures; never changes persistent tables.
require_once dirname(__DIR__,2) . '/classes/dbconnection.class.php';
require_once dirname(__DIR__,2) . '/classes/services/BrowserFaceService.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=(new dbconnection())->connect('ccc_database_test');
if ($db->query('SELECT DATABASE()')->fetch_row()[0] !== 'ccc_database_test') throw new RuntimeException('Test database required');
$schema=<<<'SQL'
CREATE TEMPORARY TABLE face_model_version(id INT AUTO_INCREMENT PRIMARY KEY,model_name VARCHAR(100),preprocessing_version VARCHAR(100),model_checksum CHAR(64),embedding_dimension INT,status VARCHAR(30));
CREATE TEMPORARY TABLE member(id INT PRIMARY KEY,contact_id INT,bid INT,status INT,park INT);
CREATE TEMPORARY TABLE contact(id INT PRIMARY KEY,fname VARCHAR(50),lname VARCHAR(50),park INT);
CREATE TEMPORARY TABLE face_template(id INT AUTO_INCREMENT PRIMARY KEY,member_id INT,model_version_id INT,encrypted_embedding MEDIUMBLOB,encryption_key_id VARCHAR(100),embedding_dimension INT,quality_summary JSON,status VARCHAR(20),revoked_at DATETIME);
CREATE TEMPORARY TABLE biometric_consent(id INT AUTO_INCREMENT PRIMARY KEY,member_id INT,guardian_contact_id INT,notice_version VARCHAR(100),purpose VARCHAR(100),consent_status VARCHAR(30),verification_method VARCHAR(50),actor_employee_id INT,consented_at DATETIME,withdrawn_at DATETIME);
CREATE TEMPORARY TABLE face_web_request(actor_id INT,request_key VARCHAR(80),action VARCHAR(20),payload_hash CHAR(64),response JSON,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(actor_id,request_key));
CREATE TEMPORARY TABLE branch(id INT PRIMARY KEY,branch VARCHAR(100),park INT);
INSERT INTO member VALUES(1,101,2,1,0),(2,102,2,1,0),(3,103,2,1,0),(4,104,3,1,0);
INSERT INTO contact VALUES(101,'Public','A',0),(102,'Public','B',0),(103,'Public','C',0),(104,'Other','Branch',0);
INSERT INTO branch VALUES(2,'Testing',0),(3,'Other Branch',0);
SQL;
$db->multi_query($schema);while($db->more_results()){$db->next_result();}
$contract=BrowserFacePolicy::contract();
$stmt=$db->prepare('INSERT INTO face_model_version(model_name,preprocessing_version,model_checksum,embedding_dimension,status) VALUES(?,?,?,128,\'PENDING_CALIBRATION\')');
$stmt->bind_param('sss',$contract['model_version'],$contract['preprocessing_version'],$contract['recognizer_source_sha256']);$stmt->execute();
$cipher=new FaceEmbeddingCipher(str_repeat('t',32),'test-only');
// Test-only constructor injection. No deployed configuration bypass exists.
$service=new BrowserFaceService($db,$cipher,99,2,['ready'=>true,'DUPLICATE_THRESHOLD'=>.99,'ATTENDANCE_THRESHOLD'=>.99]);
function check($condition,$label){if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
function rejects(callable $call,string $label){try{$call();}catch(InvalidArgumentException $e){check(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
$a=array_fill(0,128,0.0);$a[0]=1.0;$b=array_fill(0,128,0.0);$b[1]=1.0;
function command(int $person,array $vector,string $key):array{return ['person_id'=>$person,'template'=>$vector,'model_version'=>BrowserFacePolicy::contract()['model_version'],'preprocessing_version'=>BrowserFacePolicy::contract()['preprocessing_version'],'idempotency_key'=>$key];}
$first=command(1,$a,'enroll_first_00000001');
check($service->run('enroll',$first)['state']==='ENROLLED','enrollment saves');
check($service->run('enroll',$first)['state']==='ENROLLED','idempotent lost-response retry');
check($service->run('enroll',command(2,$a,'enroll_duplicate_00001'))['state']==='DUPLICATE','central duplicate rejected');
check($service->run('enroll',command(2,$b,'enroll_distinct_000001'))['state']==='ENROLLED','distinct identity accepted');
check((int)$db->query('SELECT COUNT(*) FROM face_template')->fetch_row()[0]===2,'only two encrypted templates');
$encrypted=$db->query('SELECT encrypted_embedding FROM face_template LIMIT 1')->fetch_row()[0];
check(strpos($encrypted,'F1')===0 && $cipher->decrypt($encrypted)===$a,'template encrypted at rest');
rejects(fn()=>$service->run('enroll',command(2,$b,$first['idempotency_key'])),'changed payload conflicts');
rejects(fn()=>$service->run('enroll',command(4,$a,'enroll_otherbranch_001')),'person branch authorization');
$wrong=command(3,$a,'enroll_wrongversion_01');$wrong['preprocessing_version']='old';
rejects(fn()=>$service->run('enroll',$wrong),'preprocessing version mismatch');
foreach([[1,0],array_fill(0,128,0.0),array_fill(0,128,'0'),array_fill(0,128,NAN)] as $bad) rejects(fn()=>BrowserFaceService::vector($bad),'invalid embedding rejected');
$blocked=new BrowserFaceService($db,$cipher,99,2,['ready'=>false]);
check($blocked->run('enroll',command(3,$a,'enroll_uncalibrated_01'))['state']==='CALIBRATION_REQUIRED','calibration gate cannot be bypassed');
check($service->run('delete',['person_id'=>1,'idempotency_key'=>'delete_person_0000001'])['state']==='DELETED','biometric deletion');
check($db->query("SELECT encrypted_embedding FROM face_template WHERE member_id=1")->fetch_row()[0]==='','ciphertext erased');
check($service->run('enroll',command(1,$a,'enroll_again_000000001'))['state']==='ENROLLED','re-enrollment after deletion');
// Verify cross-connection lock contention; same lock guards all mutations.
$second=(new dbconnection())->connect('ccc_database_test');
$lock='browser-face:'.substr(hash('sha256','ccc_database_test'),0,45);
$stmt=$db->prepare('SELECT GET_LOCK(?,0)');$stmt->bind_param('s',$lock);$stmt->execute();$stmt->get_result()->fetch_row();$stmt->close();
$stmt2=$second->prepare('SELECT GET_LOCK(?,0)');$stmt2->bind_param('s',$lock);$stmt2->execute();
check((int)$stmt2->get_result()->fetch_row()[0]===0,'concurrent mutation lock enforced across connections');
$stmt=$db->prepare('SELECT RELEASE_LOCK(?)');$stmt->bind_param('s',$lock);$stmt->execute();$stmt->get_result()->fetch_row();$stmt->close();$second->close();
$before=(int)$db->query('SELECT COUNT(*) FROM biometric_consent')->fetch_row()[0];
$db->query('ALTER TABLE face_template ADD CONSTRAINT test_rollback CHECK(member_id<>3)');
$c=array_fill(0,128,0.0);$c[2]=1.0;
try{$service->run('enroll',command(3,$c,'enroll_rollback_000001'));throw new RuntimeException('Expected database failure');}
catch(mysqli_sql_exception $e){check((int)$db->query('SELECT COUNT(*) FROM biometric_consent')->fetch_row()[0]===$before,'failed insert rolls back authorization');}
check((int)$db->query("SELECT COUNT(*) FROM face_web_request WHERE request_key='enroll_rollback_000001'")->fetch_row()[0]===0,'failed attempt does not prevent retry');
check(BrowserFacePolicy::load()['ready']===false,'missing live evidence blocks deployed policy');
echo 'All browser face backend phase-3 tests passed'.PHP_EOL;
require __DIR__.'/attendance-fixture.php';
$db->query("SET time_zone='+00:00'"); // PHP is Asia/Kolkata; lease must use DB clock consistently.
$open=['batch_id'=>11,'attendance_date'=>date('Y-m-d'),'attendance_session_id'=>7,'selected_time'=>'10:00:00','idempotency_key'=>'open_session_00000001'];
$scan=$service->run('open',$open);
check($scan['state']==='OPEN','attendance session opened with eligible roster');
$attempt=['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'model_version'=>$contract['model_version'],
    'preprocessing_version'=>$contract['preprocessing_version'],'observations'=>[$a,$a,$a],'idempotency_key'=>'attendance_first_0001'];
$recorded=$service->run('attendance',$attempt);
check($recorded['state']==='RECORDED' && $recorded['person_id']===1,'approved LIVE recognition saves the correct member automatically');
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===1 && (int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0]===1,'LIVE recognition uses domain attendance and event transaction');
check($db->query('SELECT source FROM attendance_event')->fetch_row()[0]==='FACE_MATCH','LIVE event is genuine face-match source');
check($service->run('attendance',$attempt)==$recorded,'lost LIVE response retries original saved outcome');
$retry=$attempt;$retry['idempotency_key']='attendance_repeat_001';
check($service->run('attendance',$retry)['state']==='ALREADY_MARKED','repeat LIVE recognition cannot create duplicate');
check((int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0]===1,'database attendance uniqueness');
check((int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=21')->fetch_row()[0]===1,'membership deducted once');
$unknown=$attempt;$unknown['observations']=[$c,$c,$c];$unknown['idempotency_key']='attendance_unknown_01';
check($service->run('attendance',$unknown)['state']==='UNKNOWN','unknown face unmarked');
$conflict=$attempt;$conflict['observations']=[$a,$b,$a];$conflict['idempotency_key']='attendance_conflict_1';
check($service->run('attendance',$conflict)['state']==='UNKNOWN','conflicting observations unmarked');
check(BrowserFaceService::matchObservations([$a,$a,$a],[['member_id'=>1,'vector'=>$a],['member_id'=>2,'vector'=>$a]],.99)===null,'ambiguous identities unmarked');
$other=new BrowserFaceService($db,$cipher,100,2,['ready'=>true,'ATTENDANCE_THRESHOLD'=>.99,'DUPLICATE_THRESHOLD'=>.99]);
$unauthorized=$attempt;$unauthorized['idempotency_key']='attendance_otheractor';
rejects(fn()=>$other->run('attendance',$unauthorized),'attendance session actor isolation');
$open['attendance_session_id']=999;$open['idempotency_key']='open_invalid_session1';
rejects(fn()=>$service->run('open',$open),'invalid scheduled session rejected');
$open['attendance_session_id']=8;$open['selected_time']='12:00:00';$open['idempotency_key']='open_second_session01';
$scan2=$service->run('open',$open);$secondAttempt=$attempt;$secondAttempt['scan_id']=$scan2['scan_id'];$secondAttempt['scan_token']=$scan2['scan_token'];$secondAttempt['idempotency_key']='attendance_session2_1';
check($service->run('attendance',$secondAttempt)['state']==='RECORDED','LIVE saves in the correct independent session');
check((int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0]===2,'session isolation saves two events');
$db->query("UPDATE face_web_request SET response='{\"state\":\"PENDING\"}' WHERE request_key='attendance_session2_1'");
$service->run('close',['scan_id'=>$scan2['scan_id'],'scan_token'=>$scan2['scan_token'],'idempotency_key'=>'close_second_session1']);
check($service->run('attendance',$secondAttempt)['state']==='ALREADY_MARKED','LIVE crash after commit reconciles after camera close');
$newClosed=$secondAttempt;$newClosed['idempotency_key']='attendance_closed_001';
rejects(fn()=>$service->run('attendance',$newClosed),'closed session cannot mark new attendance');
$db->query('UPDATE member SET status=0 WHERE id=2');
$inactive=$attempt;$inactive['observations']=[$b,$b,$b];$inactive['idempotency_key']='attendance_inactive01';
check($service->run('attendance',$inactive)['state']==='UNKNOWN','ineligible person unmarked');
$db->query('UPDATE member SET status=1 WHERE id=2');
$service->run('delete',['person_id'=>2,'idempotency_key'=>'delete_member2_000001']);
$inactive['idempotency_key']='attendance_revoked_01';
check($service->run('attendance',$inactive)['state']==='UNKNOWN','revoked face unmarked');
$db->query("UPDATE attendance_scan SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=".(int)$scan['scan_id']);
$expired=$attempt;$expired['idempotency_key']='attendance_expired_01';
rejects(fn()=>$service->run('attendance',$expired),'expired lease rejected independently of PHP timezone');
echo 'All browser face backend phase-4 tests passed'.PHP_EOL;
for($i=0;$i<31;$i++)$db->query("INSERT INTO face_web_request(actor_id,request_key,action,payload_hash,response) VALUES(99,'rate_fixture_$i','attendance','".str_repeat('0',64)."','{}')");
try{$service->run('delete',['person_id'=>1,'idempotency_key'=>'rate_limit_new_000001']);throw new RuntimeException('Rate limit bypassed');}
catch(RuntimeException $error){check(strpos($error->getMessage(),'Too many face requests')!==false,'backend rate limiting');}
check($service->run('enroll',$first)['state']==='ENROLLED','idempotent recovery allowed after rate limit');

// Extension uses only the same connection-scoped temporary tables.
$schema2=str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',file_get_contents(dirname(__DIR__,2).'/database/migrations/20261009_02_browser_face_collection.sql'));
$db->multi_query($schema2);while($db->more_results())$db->next_result();
$db->query('DELETE FROM face_web_request');
$sample=command(3,$c,'calibration_save_00001')+['consent'=>true,'visit'=>'visit-one','device'=>'physical-test-device','lighting'=>'daylight','split'=>'calibration'];
$templatesBefore=(int)$db->query('SELECT COUNT(*) FROM face_template')->fetch_row()[0];$attendanceBefore=(int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0];
check($blocked->run('calibration_collect',$sample)['state']==='CALIBRATION_SAVED','calibration collection independent of identity gate');
check($blocked->run('calibration_collect',$sample)['sample_id']===1,'collection retry returns same observation');
$stored=$db->query('SELECT encrypted_embedding FROM face_web_calibration')->fetch_row()[0];check($cipher->decrypt($stored)===$c && strpos($stored,'F1')===0,'calibration encrypted at rest');
check((int)$db->query('SELECT COUNT(*) FROM face_template')->fetch_row()[0]===$templatesBefore && (int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===$attendanceBefore,'calibration cannot create enrollments or attendance');
$progress=$service->calibrationProgress();check($progress['identities']===1 && $progress['samples']===1 && !isset($progress['observations'][0]['encrypted_embedding']),'progress hides biometric vectors');
$noConsent=$sample;$noConsent['consent']=false;$noConsent['idempotency_key']='calibration_no_consent';rejects(fn()=>$service->run('calibration_collect',$noConsent),'explicit calibration consent enforced');
$branchSample=$sample;$branchSample['person_id']=4;$branchSample['idempotency_key']='calibration_branch_01';rejects(fn()=>$service->run('calibration_collect',$branchSample),'calibration branch authorization');
require_once dirname(__DIR__,2).'/scripts/browser_face_admin.php';
putenv('FACE_EMBEDDING_KEY='.str_repeat('t',32));putenv('FACE_EMBEDDING_KEY_ID=test-only');
$export='/tmp/browser-face-export-'.bin2hex(random_bytes(6)).'.jsonl';adminMain(['admin','export-calibration','--database','ccc_database_test','--branch','2','--output',$export],$db);
$exported=json_decode(trim(file_get_contents($export)),true);check($exported['identity']==='3' && array_map('floatval',$exported['embedding'])===$c && (fileperms($export)&0777)===0600,'private CLI export uses correct identity/vector and mode 0600');unlink($export);
rejects(fn()=>privateFile(dirname(__DIR__,2).'/forbidden-face-export.jsonl','private'),'export into public webroot rejected');
$db->query('UPDATE face_web_calibration SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND)');check($service->calibrationProgress()['samples']===0,'expired observations excluded');
adminMain(['admin','purge-expired','--database','ccc_database_test','--branch','2','--apply'],$db);check($db->query('SELECT encrypted_embedding FROM face_web_calibration')->fetch_row()[0]==='','expiry purge erases ciphertext');
$sample['idempotency_key']='calibration_save_00002';$sample['split']='validation';$service->run('calibration_collect',$sample);$service->run('calibration_delete',['person_id'=>3,'idempotency_key'=>'calibration_delete_01']);check($service->calibrationProgress()['samples']===0,'consent withdrawal erases selected identity observations');
$db->query("INSERT INTO face_model_version(model_name,preprocessing_version,model_checksum,embedding_dimension,status) VALUES('legacy','legacy-v1','".str_repeat('b',64)."',128,'ACTIVE')");$legacyModel=(int)$db->insert_id;
$oldEncrypted=$cipher->encrypt($c);$s=$db->prepare("INSERT INTO face_template(member_id,model_version_id,encrypted_embedding,encryption_key_id,embedding_dimension,status) VALUES(2,?,?,'test-only',128,'ACTIVE')");$s->bind_param('is',$legacyModel,$oldEncrypted);$s->execute();$oldId=(int)$db->insert_id;
$migrate=command(2,$b,'migration_replace_001')+['legacy_template_ids'=>[$oldId]];
check($service->run('enroll',command(3,$c,'legacy_blocks_new_001'))['state']==='INCOMPATIBLE_TEMPLATES','legacy templates block ordinary new enrollment');
rejects(fn()=>$service->run('migrate',$migrate),'migration cannot run without explicit approval');
check($service->migrationStatus()['incompatible_active_count']===1,'migration status identifies incompatible active templates');
$policyFile='/tmp/browser-face-migration-policy-'.bin2hex(random_bytes(5)).'.json';
$approval=['database'=>'ccc_database_test','approved'=>true,'approved_by'=>'regression-test-only','expires_at'=>gmdate('c',time()+3600),'members'=>[['member_id'=>2,'branch_id'=>2,'legacy_template_ids'=>[$oldId],'fingerprint'=>hash('sha256',$oldId.':'.$legacyModel.':'.hash('sha256',$oldEncrypted))]]];file_put_contents($policyFile,json_encode($approval));chmod($policyFile,0600);putenv('FACE_WEB_LEGACY_MIGRATION_FILE='.$policyFile);
check($service->migrationStatus()['legacy_templates'][0]['migration_authorized']===true,'specific member migration authorization reported');
check($blocked->run('migrate',array_replace($migrate,['idempotency_key'=>'migration_calibration1']))['state']==='CALIBRATION_REQUIRED','migration preserves calibration gate');
$wrongSet=$migrate;$wrongSet['legacy_template_ids']=[$oldId+1];rejects(fn()=>$service->run('migrate',$wrongSet),'changed template IDs rejected');
$duplicate=$migrate;$duplicate['template']=$a;$duplicate['idempotency_key']='migration_duplicate01';check($service->run('migrate',$duplicate)['state']==='DUPLICATE','replacement retains compatible duplicate protection');
check($db->query("SELECT status FROM face_template WHERE id=$oldId")->fetch_row()[0]==='ACTIVE','duplicate leaves legacy active');
$db->query("ALTER TABLE face_template ADD CONSTRAINT migration_fail CHECK(NOT(member_id=2 AND model_version_id=1 AND status='ACTIVE'))");
try{$service->run('migrate',$migrate);throw new RuntimeException('Expected replacement failure');}catch(mysqli_sql_exception $e){check($db->query("SELECT status FROM face_template WHERE id=$oldId")->fetch_row()[0]==='ACTIVE' && (int)$db->query('SELECT COUNT(*) FROM face_web_migration')->fetch_row()[0]===0,'replacement failure rolls back legacy/audit atomically');}
$db->query('ALTER TABLE face_template DROP CHECK migration_fail');
$replacement=$service->run('migrate',$migrate);check($replacement['state']==='ENROLLED','authorized replacement activates current template');
check($service->run('migrate',$migrate)['replacement_template_id']===$replacement['replacement_template_id'],'migration retry recovers exact replacement');
check($db->query("SELECT status FROM face_template WHERE id=$oldId")->fetch_row()[0]==='SUPERSEDED' && $db->query("SELECT encrypted_embedding FROM face_template WHERE id=$oldId")->fetch_row()[0]===$oldEncrypted,'legacy ciphertext retained as inactive history');
check($service->migrationStatus()['incompatible_active_count']===0,'legacy blocker resolved after authorized replacement');
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===$attendanceBefore,'migration preserves attendance history');
$migrationId=(int)$db->query('SELECT id FROM face_web_migration')->fetch_row()[0];adminMain(['admin','rollback-migration','--database','ccc_database_test','--branch','2','--migration-id',(string)$migrationId,'--apply','--authorized-by','regression-test-only'],$db);
check($service->migrationStatus()['incompatible_active_count']===1,'explicit rollback restores legacy and compatibility gate');
check($service->run('migrate',$migrate)['state']==='MIGRATION_ROLLED_BACK','retry after rollback reports truthful outcome');
$s=$db->prepare("INSERT INTO face_template(member_id,model_version_id,encrypted_embedding,encryption_key_id,embedding_dimension,status) VALUES(4,?,?,'test-only',128,'ACTIVE')");$s->bind_param('is',$legacyModel,$oldEncrypted);$s->execute();
$migrate['idempotency_key']='migration_other_scope';check($service->run('migrate',$migrate)['state']==='MIGRATION_SCOPE_BLOCKED','migration cannot bypass another incompatible identity across tenant branches');
$retire=['admin','retire-templates','--database','ccc_database_test','--branch','2','--member','2','--template-ids',(string)$oldId,'--fingerprint',$approval['members'][0]['fingerprint'],'--authorized-by','regression-test-only','--apply'];
$wrongRetire=$retire;$wrongRetire[11]=str_repeat('0',64);try{adminMain($wrongRetire,$db);check(false,'retirement must reject changed fingerprint');}catch(RuntimeException $e){check($e->getMessage()==='Template fingerprint changed','legacy retirement rejects changed fingerprint');}
$wrongRetire=$retire;$wrongRetire[5]='3';try{adminMain($wrongRetire,$db);check(false,'retirement must reject wrong branch');}catch(RuntimeException $e){check($e->getMessage()==='Template set/member/branch changed','legacy retirement rejects wrong branch');}
adminMain($retire,$db);check($db->query("SELECT status,LENGTH(encrypted_embedding) FROM face_template WHERE id=$oldId")->fetch_row()===['REVOKED','0'],'authorized legacy retirement erases only selected ciphertext');
adminMain($retire,$db);check((int)$db->query("SELECT COUNT(*) FROM face_template WHERE member_id=4 AND status='ACTIVE'")->fetch_row()[0]===1,'retirement retry preserves other branch templates');
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===$attendanceBefore,'legacy retirement preserves attendance');
$pending=command(3,$c,'pending_capture_000001')+['consent'=>true,'device'=>'camera:test-hash','lighting'=>'measured-normal'];
$beforePending=(int)$db->query('SELECT COUNT(*) FROM face_template')->fetch_row()[0];$captured=$blocked->run('enroll',$pending);
check($captured['state']==='ENROLLMENT_PENDING' && $captured['contact_id']===103,'attendance enrollment automatically collects pending calibration');
check($blocked->run('enroll',$pending)['sample_id']===$captured['sample_id'],'pending enrollment retries safely');
$pending['idempotency_key']='pending_capture_000002';$blocked->run('enroll',$pending);
$rows=$db->query("SELECT visit,dataset_split,encrypted_embedding FROM face_web_calibration WHERE deleted_at IS NULL AND expires_at>NOW() ORDER BY id")->fetch_all(MYSQLI_ASSOC);
check(count($rows)===2 && $rows[0]['visit']===$rows[1]['visit'] && $rows[0]['dataset_split']==='calibration' && $rows[1]['dataset_split']==='calibration','same-day captures are not invented independent validation visits');
check($cipher->decrypt($rows[0]['encrypted_embedding'])===$c && (int)$db->query('SELECT COUNT(*) FROM face_template')->fetch_row()[0]===$beforePending,'pending samples encrypted without active enrollment');
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===$attendanceBefore,'pending enrollment cannot mark attendance');
$options=$blocked->options();$option=array_values(array_filter($options['persons'],fn($p)=>(int)$p['id']===3))[0];check((int)$option['pending_samples']===2 && (int)$option['enrolled']===0,'pending row status survives reload without claiming enrollment');
$bad=$pending;$bad['person_id']=4;$bad['idempotency_key']='pending_wrong_branch01';rejects(fn()=>$blocked->run('enroll',$bad),'pending enrollment retains branch authorization');
$bad=$pending;$bad['lighting']='invented';$bad['idempotency_key']='pending_bad_lighting01';rejects(fn()=>$blocked->run('enroll',$bad),'pending collection validates measured lighting');
$db->query("UPDATE face_web_calibration SET visit=CONCAT('automatic-date:',DATE_SUB(CURRENT_DATE,INTERVAL 1 DAY)) WHERE id=".(int)$captured['sample_id']);
$db->query("UPDATE face_web_calibration SET visit=CONCAT('automatic-date:',DATE_SUB(CURRENT_DATE,INTERVAL 2 DAY)) WHERE id<>".(int)$captured['sample_id']." AND deleted_at IS NULL AND expires_at>NOW()");
$pending['idempotency_key']='pending_heldout_day001';$heldOut=$blocked->run('enroll',$pending);
check($db->query('SELECT dataset_split FROM face_web_calibration WHERE id='.(int)$heldOut['sample_id'])->fetch_row()[0]==='validation','later actual dates assigned to held-out validation');
$pending['idempotency_key']='pending_heldout_day002';$again=$blocked->run('enroll',$pending);check($db->query('SELECT dataset_split FROM face_web_calibration WHERE id='.(int)$again['sample_id'])->fetch_row()[0]==='validation','repeat on validation date keeps independent split');
unlink($policyFile);putenv('FACE_WEB_LEGACY_MIGRATION_FILE');putenv('FACE_EMBEDDING_KEY');putenv('FACE_EMBEDDING_KEY_ID');
echo "All encrypted collection and controlled legacy migration assertions passed\n";
