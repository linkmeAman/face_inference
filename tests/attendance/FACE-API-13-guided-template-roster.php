<?php
declare(strict_types=1);
require_once __DIR__.'/helpers/face-api-recovery-fixture.php';
date_default_timezone_set('UTC');
// Expected corrupt-template paths log errors; case results still report every exception.
ini_set('error_log', '/dev/null');
try { $db=recoveryDb(); }
catch(Throwable $e) { fwrite(STDERR,'BLOCKED: '.$e->getMessage().PHP_EOL); exit(2); }
final class GuidedRosterProvider extends RecoveryLegacyProvider {
    public array $received=[];
    public function initializeScan(string $id,array $candidates,int $ttl): array {
        $this->received=$candidates;
        return parent::initializeScan($id,$candidates,$ttl);
    }
}
function guidedRoster(mysqli $db,int $count,bool $corrupt=false): array {
    recoveryReset($db);
    putenv('FACE_TEACHER_SELECTION_ENABLED=1');
    $cipher=new FaceEmbeddingCipher(str_repeat('k',32),'test-key-v1');
    $expected=[];
    for($i=0;$i<$count;$i++) {
        $vector=[.1,($i+1)/100,.3]; $expected[]=$vector;
        $encrypted=$db->real_escape_string($corrupt && $i===$count-1?'corrupt':$cipher->encrypt($vector));
        $db->query("INSERT INTO face_template (member_id,encrypted_embedding,status) VALUES (101,'{$encrypted}','ACTIVE')");
    }
    $db->query("INSERT INTO biometric_consent(member_id,consent_status) VALUES (101,'PROGRAM_AUTHORIZED')");
    $provider=new GuidedRosterProvider();
    $service=new FaceAttendanceApiService($db,$provider,$cipher,new FaceAttendanceFeatureConfig(true,true,false,false));
    $scan=$service->startFaceScan(['branch_id'=>7,'batch_id'=>11,'attendance_date'=>date('Y-m-d'),'session_id'=>4,
        'selected_time'=>'10:00:00','event_type'=>'PUNCH_IN','actor_employee_id'=>9001,'check_month'=>false,
        'browser_session_binding'=>hash('sha256','guided')]);
    return [$scan,$provider,$expected];
}
$cases=[
    'all_six_actual_templates_reach_inference'=>function()use($db){
        [$scan,$provider,$expected]=guidedRoster($db,6);
        recoveryAssert(($scan['state']??'')==='STARTED','six-template scan starts');
        recoveryAssert(($provider->received[0]['embeddings']??[])===$expected,'all six independent templates must reach inference');
        recoveryAssert((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===0,'no attendance write');
        recoveryAssert((int)$db->query('SELECT SUM(sessions_used) FROM invoice_item')->fetch_row()[0]===2,'no deduction');
    },
    'legacy_three_templates_are_unchanged'=>function()use($db){
        [$scan,$provider,$expected]=guidedRoster($db,3);
        recoveryAssert(($scan['state']??'')==='STARTED','legacy scan starts');
        recoveryAssert(($provider->received[0]['embeddings']??[])===$expected,'legacy vectors unchanged');
    },
    'corrupt_sixth_template_still_blocks_scan'=>function()use($db){
        [$scan,$provider]=guidedRoster($db,6,true);
        recoveryAssert(($scan['state']??'')==='REJECTED','corruption blocks scan');
        recoveryAssert($provider->received===[],'corruption never reaches inference');
        recoveryAssert((int)$db->query("SELECT COUNT(*) FROM face_template WHERE status='QUARANTINED'")->fetch_row()[0]===1,'corruption quarantined');
    }
];
$passed=true;
foreach($cases as $name=>$test) $passed=recoveryCase($name,$test)&&$passed;
$db->close();exit($passed?0:1);
