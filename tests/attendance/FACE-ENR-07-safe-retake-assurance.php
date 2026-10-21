<?php
declare(strict_types=1);
define('FACE_ENR_06_CONTINUE_FOR_RETAKE_TESTS', true);
require __DIR__ . '/FACE-ENR-06-two-burst-assurance.php';
define('FACE_SESSION_CLEANUP_LIBRARY_ONLY', true);
require_once dirname(__DIR__, 2) . '/classes/cron.face_session_cleanup.php';

$db->query("ALTER TABLE face_identity_reference ADD capture_session_id BIGINT UNSIGNED NULL, ADD cleared_at DATETIME NULL, ADD superseded_at DATETIME NULL, ADD enrollment_session_id BIGINT UNSIGNED NULL, ADD preprocessing_version VARCHAR(100) NULL");
$db->query("ALTER TABLE face_template ADD capture_session_id BIGINT UNSIGNED NULL, ADD superseded_at DATETIME NULL");
$db->query("ALTER TABLE face_enrollment_attempt ADD capture_session_id BIGINT UNSIGNED NULL, ADD identity_drift_score DECIMAL(5,4) NULL, ADD model_version_id INT NULL, ADD recognition_generation CHAR(64) NULL, ADD admin_override_actor_id INT NULL, ADD admin_override_reason VARCHAR(255) NULL, ADD admin_override_at DATETIME NULL");
$db->query("CREATE TEMPORARY TABLE face_enrollment_capture_session (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,token_hash CHAR(64) NOT NULL,member_id INT NOT NULL,subject_id INT NOT NULL,branch_id INT NOT NULL,actor_employee_id INT NOT NULL,browser_session_hash CHAR(64) NOT NULL,reference_id INT NULL,mode VARCHAR(20) NOT NULL,status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',created_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,cleared_at DATETIME NULL,completed_at DATETIME NULL,UNIQUE KEY(token_hash)) ENGINE=InnoDB");

function retakeReset(mysqli $db): void {
    $db->query('TRUNCATE TABLE face_enrollment_capture_session');
    assuranceReset($db);
}
function retakeCommand(array $overrides = []): array {
    return array_replace(['member_id'=>101,'branch_id'=>7,'actor_employee_id'=>9001,'browser_session_id'=>'browser-one','mode'=>'ENROLLMENT'], $overrides);
}
function retakeBegin(FaceEnrollmentService $service, array $overrides = []): array {
    return $service->beginEnrollment(retakeCommand($overrides));
}
function retakeOnboard(FaceEnrollmentService $service, string $token, array $overrides = []): array {
    return $service->onboardReference(array_replace(retakeCommand(['capture_session_token'=>$token]), ['portrait'=>'portrait-bytes'], $overrides));
}
function retakeEnroll(FaceEnrollmentService $service, string $token, int $referenceId): array {
    return $service->enroll(assuranceEnrollCommand(['capture_session_token'=>$token,'browser_session_id'=>'browser-one','reference_id'=>$referenceId]));
}
function retakeAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function retakeSeedActive(mysqli $db, FaceEmbeddingCipher $cipher): void {
    $db->query("INSERT INTO biometric_subject(id,contact_id,status) VALUES(1,1101,'ACTIVE')");
    $portrait = $db->real_escape_string($cipher->encryptBytes('active-portrait'));
    $embedding = $db->real_escape_string($cipher->encrypt(vector128(1.0)));
    $db->query("INSERT INTO face_identity_reference(subject_id,encrypted_portrait,encrypted_embedding,encryption_key_id,embedding_dimension,source,model_version_id,policy_version,verifier_actor_id,verified_at,status,preprocessing_version) VALUES(1,'{$portrait}','{$embedding}','test-key-v1',128,'STAFF_ONBOARDING',1,'face-policy-v2',9001,NOW(),'ACTIVE','')");
    $db->query("INSERT INTO face_template(member_id,subject_id,reference_id,model_version_id,encrypted_embedding,encryption_key_id,embedding_dimension,status) VALUES(101,1,1,1,'{$embedding}','test-key-v1',128,'ACTIVE')");
    $db->query("INSERT INTO biometric_consent(member_id,notice_version,purpose,consent_status,verification_method,actor_employee_id,consented_at) VALUES(101,'program-face-v1','attendance','PROGRAM_AUTHORIZED','PROGRAM_PARTICIPATION',9001,NOW())");
}
final class RetakeRaceInference implements FaceEnrollmentInferenceClient {
    public $onReference = null;
    public $onEnrollment = null;
    public function encodeReference(string $portrait): array {
        if ($this->onReference) ($this->onReference)();
        return validReferenceResult();
    }
    public function encodeEnrollment(array $frames, array $contexts = []): array { return validTwoBurstResult(); }
    public function encodeEnrollmentV2(array $a, array $b, array $reference): array {
        if ($this->onEnrollment) ($this->onEnrollment)();
        return validTwoBurstResult();
    }
}

$retake = [];
assuranceCase('RET-01 clear erases pending reference and is idempotent', function () use ($db,$cipher): void {
    retakeReset($db);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult()),$cipher);
    $begin = retakeBegin($service);
    retakeAssert(strlen(json_encode($begin)) < 4096, 'begin payload budget');
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    assuranceAssert('ACTIVE',$ref['state'],'onboard state');
    $resume = retakeBegin($service,['capture_session_token'=>$begin['capture_session_token']]);
    retakeAssert($resume['has_pending_reference'] && strlen(json_encode($resume)) < 65000,'portrait resume payload budget');
    retakeAssert(strpos(json_encode($resume),'embedding') === false,'embedding exposed in begin response');
    assuranceAssert(null,$service->getActiveReference(1101),'pending must not be active');
    $command = retakeCommand(['capture_session_token'=>$begin['capture_session_token'],'reference_id'=>$ref['reference_id']]);
    assuranceAssert('CLEARED',$service->clearReference($command)['state'],'first clear');
    assuranceAssert('CLEARED',$service->clearReference($command)['state'],'second clear');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_embedding FROM face_identity_reference WHERE id=".(int)$ref['reference_id']),'embedding erased');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_portrait FROM face_identity_reference WHERE id=".(int)$ref['reference_id']),'portrait erased');
    assuranceAssert('REJECTED',$service->enroll(assuranceEnrollCommand(['capture_session_token'=>$begin['capture_session_token'],'browser_session_id'=>'browser-one']))['state'],'late enroll rejected');
},$retake);

assuranceCase('RET-02 replacement erases old session and blocks late inference', function () use ($db,$cipher): void {
    retakeReset($db);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult()),$cipher);
    $first = retakeBegin($service);
    $ref = retakeOnboard($service,$first['capture_session_token']);
    $second = retakeBegin($service);
    retakeAssert($first['capture_session_token'] !== $second['capture_session_token'],'new token required');
    assuranceAssert('CANCELLED',(string)assuranceScalar($db,"SELECT status FROM face_enrollment_capture_session ORDER BY id LIMIT 1"),'old session cancelled');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_portrait FROM face_identity_reference WHERE id=".(int)$ref['reference_id']),'old portrait erased');
    assuranceAssert('REJECTED',retakeOnboard($service,$first['capture_session_token'])['state'],'late onboard rejected');
},$retake);

assuranceCase('RET-03 new session enrolls from pending reference', function () use ($db,$cipher): void {
    retakeReset($db);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult(),validTwoBurstResult()),$cipher);
    $begin = retakeBegin($service);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    $enroll = retakeEnroll($service,$begin['capture_session_token'],(int)$ref['reference_id']);
    assuranceAssert('ACTIVE',$enroll['state'],'enroll state');
    assuranceAssert(3,(int)assuranceScalar($db,"SELECT COUNT(*) FROM face_template WHERE status='ACTIVE'"),'active templates');
    retakeAssert(strpos(json_encode($enroll),'embedding') === false,'embedding exposed in response');
    retakeAssert(strpos(json_encode($service->getActiveReference(1101,true,false)),'embedding') === false,'embedding exposed in getReference DTO');
},$retake);

assuranceCase('RET-04 normal re-enrollment atomically supersedes old records', function () use ($db,$cipher): void {
    retakeReset($db);
    retakeSeedActive($db,$cipher);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult(vector128(1.0)),validTwoBurstResult(vector128(1.0))),$cipher);
    $begin = retakeBegin($service,['mode'=>'REENROLLMENT']);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    assuranceAssert(false,$ref['early_drift_warning'],'no drift warning');
    assuranceAssert('ACTIVE',(string)assuranceScalar($db,"SELECT status FROM face_identity_reference WHERE id=1"),'old reference stays active until commit');
    $result = retakeEnroll($service,$begin['capture_session_token'],(int)$ref['reference_id']);
    assuranceAssert('ACTIVE',$result['state'],'normal re-enrollment');
    assuranceAssert('SUPERSEDED',(string)assuranceScalar($db,"SELECT status FROM face_identity_reference WHERE id=1"),'old reference superseded');
    assuranceAssert('SUPERSEDED',(string)assuranceScalar($db,"SELECT status FROM face_template WHERE id=1"),'old template superseded');
    assuranceAssert(3,(int)assuranceScalar($db,"SELECT COUNT(*) FROM face_template WHERE status='ACTIVE'"),'new templates active');
},$retake);

assuranceCase('RET-05 drift remains pending until admin approval', function () use ($db,$cipher): void {
    retakeReset($db);
    retakeSeedActive($db,$cipher);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult(vector128(0.5)),validTwoBurstResult(vector128(0.5))),$cipher);
    $begin = retakeBegin($service,['mode'=>'REENROLLMENT']);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    assuranceAssert(true,$ref['early_drift_warning'],'early drift warning');
    $result = retakeEnroll($service,$begin['capture_session_token'],(int)$ref['reference_id']);
    assuranceAssert('PENDING_ADMIN_REVIEW',$result['state'],'review state');
    $reviews = $service->listReenrollmentReviews(7);
    assuranceAssert(1,count($reviews['reviews']),'pending admin list');
    retakeAssert(strpos(json_encode($reviews),'embedding') === false,'embedding exposed in admin list');
    assuranceAssert('ACTIVE',(string)assuranceScalar($db,"SELECT status FROM face_template WHERE id=1"),'old template remains active');
    assuranceAssert(3,(int)assuranceScalar($db,"SELECT COUNT(*) FROM face_template WHERE status='PENDING_APPROVAL'"),'encrypted candidates saved');
    assuranceAssert('UNAUTHORIZED_ADMIN_OVERRIDE',$service->reviewReenrollmentAttempt((int)$result['attempt_id'],'APPROVED',9009,true,'Verified child in person',2)['reason'],'non-admin blocked');
    assuranceAssert('APPROVED',$service->reviewReenrollmentAttempt((int)$result['attempt_id'],'APPROVED',9009,true,'Verified child in person',1)['state'],'admin approval');
    assuranceAssert('SUPERSEDED',(string)assuranceScalar($db,"SELECT status FROM face_template WHERE id=1"),'old template superseded after approval');
    assuranceAssert(3,(int)assuranceScalar($db,"SELECT COUNT(*) FROM face_template WHERE status='ACTIVE'"),'candidates promoted');
},$retake);

assuranceCase('RET-06 rejection erases candidates while old enrollment stays active', function () use ($db,$cipher): void {
    retakeReset($db);
    retakeSeedActive($db,$cipher);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult(vector128(0.5)),validTwoBurstResult(vector128(0.5))),$cipher);
    $begin = retakeBegin($service,['mode'=>'REENROLLMENT']);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    $result = retakeEnroll($service,$begin['capture_session_token'],(int)$ref['reference_id']);
    assuranceAssert('REJECTED',$service->reviewReenrollmentAttempt((int)$result['attempt_id'],'REJECTED',9009,true,'Wrong child',1)['state'],'admin rejection');
    assuranceAssert('ACTIVE',(string)assuranceScalar($db,"SELECT status FROM face_template WHERE id=1"),'old template retained');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_embedding FROM face_template WHERE id=2"),'candidate erased');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_portrait FROM face_identity_reference WHERE id=2"),'portrait erased');
},$retake);

assuranceCase('RET-07 protected active reference cannot be cleared', function () use ($db,$cipher): void {
    retakeReset($db);
    retakeSeedActive($db,$cipher);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult()),$cipher);
    $begin = retakeBegin($service,['mode'=>'REENROLLMENT']);
    $result = $service->clearReference(retakeCommand(['capture_session_token'=>$begin['capture_session_token'],'reference_id'=>1]));
    assuranceAssert('PROTECTED_ACTIVE_REFERENCE',$result['reason'],'active reference protection');
    assuranceAssert('ACTIVE',(string)assuranceScalar($db,"SELECT status FROM face_identity_reference WHERE id=1"),'active reference retained');
},$retake);

assuranceCase('RET-08 clear during reference inference rejects late commit', function () use ($db,$cipher): void {
    retakeReset($db);
    $race = new RetakeRaceInference();
    $service = new FaceEnrollmentService($db,$race,$cipher);
    $begin = retakeBegin($service);
    $race->onReference = function () use ($service,$begin): void {
        assuranceAssert('CLEARED',$service->clearReference(retakeCommand(['capture_session_token'=>$begin['capture_session_token']]))['state'],'clear during reference inference');
    };
    assuranceAssert('STALE_CAPTURE_SESSION',retakeOnboard($service,$begin['capture_session_token'])['reason'],'late reference rejected');
    assuranceAssert(0,(int)assuranceScalar($db,"SELECT COUNT(*) FROM face_identity_reference"),'no late reference stored');
},$retake);

assuranceCase('RET-09 clear during burst inference rejects late commit', function () use ($db,$cipher): void {
    retakeReset($db);
    $race = new RetakeRaceInference();
    $service = new FaceEnrollmentService($db,$race,$cipher);
    $begin = retakeBegin($service);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    $race->onEnrollment = function () use ($service,$begin,$ref): void {
        assuranceAssert('CLEARED',$service->clearReference(retakeCommand(['capture_session_token'=>$begin['capture_session_token'],'reference_id'=>$ref['reference_id']]))['state'],'clear during burst inference');
    };
    assuranceAssert('STALE_CAPTURE_SESSION',retakeEnroll($service,$begin['capture_session_token'],(int)$ref['reference_id'])['reason'],'late burst rejected');
    assuranceAssert(0,(int)assuranceScalar($db,"SELECT COUNT(*) FROM face_template"),'no late template stored');
},$retake);

assuranceCase('RET-10 withdrawal cancels capture session', function () use ($db,$cipher): void {
    retakeReset($db);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult()),$cipher);
    $begin = retakeBegin($service);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    $service->withdraw(101,9001,7);
    assuranceAssert('CANCELLED',(string)assuranceScalar($db,"SELECT status FROM face_enrollment_capture_session WHERE id=1"),'session cancelled');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_portrait FROM face_identity_reference WHERE id=".(int)$ref['reference_id']),'portrait erased');
    assuranceAssert('STALE_CAPTURE_SESSION',retakeOnboard($service,$begin['capture_session_token'])['reason'],'late capture rejected');
},$retake);

assuranceCase('RET-11 withdrawn consent blocks admin approval and erases candidates', function () use ($db,$cipher): void {
    retakeReset($db);
    retakeSeedActive($db,$cipher);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult(vector128(0.5)),validTwoBurstResult(vector128(0.5))),$cipher);
    $begin = retakeBegin($service,['mode'=>'REENROLLMENT']);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    $result = retakeEnroll($service,$begin['capture_session_token'],(int)$ref['reference_id']);
    $db->query("UPDATE biometric_consent SET consent_status='WITHDRAWN' WHERE member_id=101");
    assuranceAssert('REVIEW_CONTEXT_CHANGED',$service->reviewReenrollmentAttempt((int)$result['attempt_id'],'APPROVED',9009,true,'Verified child',1)['reason'],'consent check');
    assuranceAssert('ACTIVE',(string)assuranceScalar($db,"SELECT status FROM face_template WHERE id=1"),'old active stays');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_embedding FROM face_template WHERE id=2"),'candidate erased');
},$retake);

assuranceCase('RET-12 changed recognition generation blocks admin approval', function () use ($db,$cipher): void {
    retakeReset($db);
    retakeSeedActive($db,$cipher);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult(vector128(0.5)),validTwoBurstResult(vector128(0.5))),$cipher);
    $begin = retakeBegin($service,['mode'=>'REENROLLMENT']);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    $result = retakeEnroll($service,$begin['capture_session_token'],(int)$ref['reference_id']);
    $_SERVER['FACE_MODEL_VERSION_ID'] = '2';
    try { assuranceAssert('REVIEW_CONTEXT_CHANGED',$service->reviewReenrollmentAttempt((int)$result['attempt_id'],'APPROVED',9009,true,'Verified child',1)['reason'],'generation check'); }
    finally { unset($_SERVER['FACE_MODEL_VERSION_ID']); }
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_embedding FROM face_template WHERE id=2"),'candidate erased');
},$retake);

assuranceCase('RET-13 hourly cleanup erases expired active and admin-review candidates', function () use ($db,$cipher): void {
    retakeReset($db);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult()),$cipher);
    $begin = retakeBegin($service);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    $db->query("UPDATE face_enrollment_capture_session SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)");
    assuranceAssert(1,cleanupFaceSessions($db),'one active session expired');
    assuranceAssert('EXPIRED',(string)assuranceScalar($db,"SELECT status FROM face_enrollment_capture_session WHERE id=1"),'active session expired');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_portrait FROM face_identity_reference WHERE id=".(int)$ref['reference_id']),'active pending portrait erased');

    retakeReset($db);
    retakeSeedActive($db,$cipher);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult(vector128(0.5)),validTwoBurstResult(vector128(0.5))),$cipher);
    $begin = retakeBegin($service,['mode'=>'REENROLLMENT']);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    $enroll = retakeEnroll($service,$begin['capture_session_token'],(int)$ref['reference_id']);
    assuranceAssert('PENDING_ADMIN_REVIEW',$enroll['state'],'review pending before expiry');
    $db->query("UPDATE face_enrollment_capture_session SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)");
    assuranceAssert(1,cleanupFaceSessions($db),'one review expired');
    assuranceAssert('EXPIRED',(string)assuranceScalar($db,"SELECT decision FROM face_enrollment_attempt WHERE id=".(int)$enroll['attempt_id']),'attempt expired');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_embedding FROM face_template WHERE id=2"),'candidate embedding erased');
    assuranceAssert('ACTIVE',(string)assuranceScalar($db,"SELECT status FROM face_template WHERE id=1"),'old enrollment retained');
},$retake);

assuranceCase('RET-14 replacing a pending review atomically erases all candidates', function () use ($db,$cipher): void {
    retakeReset($db);
    retakeSeedActive($db,$cipher);
    $service = new FaceEnrollmentService($db,new AssuranceFakeInference(validReferenceResult(vector128(0.5)),validTwoBurstResult(vector128(0.5))),$cipher);
    $begin = retakeBegin($service,['mode'=>'REENROLLMENT']);
    $ref = retakeOnboard($service,$begin['capture_session_token']);
    $result = retakeEnroll($service,$begin['capture_session_token'],(int)$ref['reference_id']);
    assuranceAssert('PENDING_ADMIN_REVIEW',$result['state'],'review pending');
    $replacement = retakeBegin($service,['mode'=>'REENROLLMENT']);
    retakeAssert($replacement['capture_session_token'] !== $begin['capture_session_token'],'replacement token');
    assuranceAssert('CANCELLED',(string)assuranceScalar($db,"SELECT status FROM face_enrollment_capture_session WHERE id=1"),'old session cancelled');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_portrait FROM face_identity_reference WHERE id=2"),'review portrait erased');
    assuranceAssert('',(string)assuranceScalar($db,"SELECT encrypted_embedding FROM face_template WHERE id=2"),'review embedding erased');
    assuranceAssert('ACTIVE',(string)assuranceScalar($db,"SELECT status FROM face_template WHERE id=1"),'old active remains');
},$retake);

assuranceCase('RET-15 preview compares only the current session reference', function () use ($db,$cipher): void {
    retakeReset($db);
    retakeSeedActive($db,$cipher);
    $inference = new AssuranceFakeInference(validReferenceResult(vector128(0.5)));
    $inference->evidenceResult = ['face_count'=>1,'selected_face_index'=>0,'embedding'=>vector128(0.5),'top_match'=>null];
    $service = new FaceEnrollmentService($db,$inference,$cipher);
    $begin = retakeBegin($service,['mode'=>'REENROLLMENT']);
    $command = retakeCommand(['capture_session_token'=>$begin['capture_session_token']]);
    $before = $service->checkPreviewCollision('frame',7,1101,null,$command);
    assuranceAssert('NO_REFERENCE',$before['reference_match_state'],'old active reference excluded from capture preview');
    retakeOnboard($service,$begin['capture_session_token']);
    $after = $service->checkPreviewCollision('frame',7,1101,null,$command);
    assuranceAssert('MATCH',$after['reference_match_state'],'pending session reference used');
},$retake);

$failed = 0;
foreach ($retake as $result) {
    if ($result['status'] === 'PASS') echo "PASS {$result['name']}\n";
    else { $failed++; fwrite(STDERR,"FAIL {$result['name']}: {$result['message']}\n"); }
}
echo count($retake) . " retake scenarios, {$failed} failed.\n";
exit($failed ? 1 : 0);
