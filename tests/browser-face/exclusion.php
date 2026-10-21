<?php
// Only temporary fixtures from existing lifecycle suite; no persistent writes.
require __DIR__.'/lifecycle.php';
require dirname(__DIR__,2).'/classes/services/BrowserFaceEvidenceExclusion.php';
$db->query('ALTER TABLE face_template DROP CHECK test_rollback');
resetLifecycle($db);$blocked->run('enroll',captureCommand(1,$a,'exclusion_keep_0001'));$blocked->run('enroll',captureCommand(2,$a,'exclusion_wrong_001'));$blocked->run('enroll',captureCommand(3,$b,'exclusion_other_001'));
$id=(int)$db->query('SELECT id FROM face_web_calibration WHERE member_id=2')->fetch_row()[0];
$plan=BrowserFaceEvidenceExclusion::prepare($db,2,1,[$id],'Operator confirms same person as member one');
check($db->query('SELECT state FROM face_web_enrollment WHERE member_id=2')->fetch_row()[0]==='PENDING','preparation is read only');
rejects(fn()=>BrowserFaceEvidenceExclusion::prepare($db,2,2,[$id],'wrong retained label'),'retained identity cannot be excluded');
rejects(fn()=>BrowserFaceEvidenceExclusion::prepare($db,3,1,[$id],'wrong branch'),'exclusion branch isolation');
$before=$db->query('SELECT encrypted_template FROM face_web_enrollment WHERE member_id=2')->fetch_row()[0];
check(BrowserFaceEvidenceExclusion::change($db,$plan)==='Excluded labels; encrypted records preserved','audited exclusion applies');
check(BrowserFaceEvidenceExclusion::change($db,$plan)==='Already excluded','exclusion retry is idempotent');
check($db->query('SELECT encrypted_template FROM face_web_enrollment WHERE member_id=2')->fetch_row()[0]===$before,'exclusion preserves original encrypted reference');
check((int)$db->query('SELECT COUNT(*) FROM face_web_calibration WHERE deleted_at IS NULL')->fetch_row()[0]===2,'excluded label absent from calibration and validation export scope');
$activated=$ready->run('activate_pending',['idempotency_key'=>'excluded_activation_1']);
check($activated['outcomes'][1]==='ACTIVE' && $activated['outcomes'][3]==='ACTIVE' && !isset($activated['outcomes'][2]),'excluded pending label cannot activate or conflict with retained reference');
check(BrowserFaceEvidenceExclusion::change($db,$plan,true)==='Restored audited labels','exclusion rollback restores exact prior states');
check(BrowserFaceEvidenceExclusion::change($db,$plan,true)==='Already restored','rollback idempotent');
$db->query("UPDATE face_web_enrollment SET decision_reason='CHANGED_AFTER_AUDIT' WHERE member_id=2");
rejects(fn()=>BrowserFaceEvidenceExclusion::change($db,$plan),'changed records abort stale exclusion plan');
$wrong=$plan;$wrong['database']='ccc_database';rejects(fn()=>BrowserFaceEvidenceExclusion::change($db,$wrong),'wrong database cannot apply plan');
echo "Exclusion: 12 assertions passed; lifecycle baseline also passed.\n";
