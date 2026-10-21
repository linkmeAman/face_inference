<?php
declare(strict_types=1);
define('FACE_ENR_06_CONTINUE_FOR_RETAKE_TESTS', true);
require __DIR__.'/FACE-ENR-06-two-burst-assurance.php';

final class GuidedFixtureInference implements FaceEnrollmentInferenceClient {
    public array $result;
    public ?string $received = null;
    public function __construct() {
        $this->result = validTwoBurstResult();
        $this->result['embeddings'] = array_fill(0, 6, vector128(1.0));
        $this->result['pose_protocol'] = 'guided-five-v1';
        $this->result['template_sources'] = ['BURST_A','BURST_A','BURST_A','BURST_A','BURST_A','BURST_B'];
        $this->result['burst_b_template_index'] = 1;
        $this->result['quality_summary']['accepted_poses'] = array_map(static function($i) {
            return ['burst'=>$i<5?'BURST_A':'BURST_B', 'index'=>$i<5?$i:$i-5,
                'pose'=>['centre','left','right','up','down','centre','centre','centre'][$i],
                'yaw'=>[0,-.12,.12,0,0,0,0,0][$i], 'pitch'=>[.55,.55,.55,.48,.62,.55,.55,.55][$i]];
        }, range(0,7));
    }
    public function encodeReference(string $frame): array { return validReferenceResult(); }
    public function encodeEnrollment(array $frames, array $contexts=[]): array { return $this->result; }
    public function encodeEnrollmentV2(array $a, array $b, array $ref, array $ca=[], array $cb=[], ?string $poseProtocol=null): array {
        $this->received=$poseProtocol; return $this->result;
    }
}
function guidedService($provider): FaceEnrollmentService {
    global $db,$cipher;
    assuranceReset($db);
    $service=new FaceEnrollmentService($db,$provider,$cipher);
    $reference=$service->onboardReference(['branch_id'=>7,'member_id'=>101,'actor_employee_id'=>9001,'portrait'=>'reference']);
    assuranceAssert('ACTIVE',$reference['state'],'fixture reference');
    return $service;
}
$guidedResults=[];
assuranceCase('guided stores six with exact provenance and forwards protocol',function() use($db,$cipher) {
    $provider=new GuidedFixtureInference(); $service=guidedService($provider);
    $result=$service->enroll(assuranceEnrollCommand(['pose_protocol'=>'guided-five-v1']));
    assuranceAssert('ACTIVE',$result['state'],'guided accepted');
    assuranceAssert('guided-five-v1',$provider->received,'protocol forwarded');
    assuranceAssert(6,(int)assuranceScalar($db,"SELECT COUNT(*) FROM face_template WHERE status='ACTIVE'"),'six active templates');
    $rows=$db->query("SELECT encrypted_embedding FROM face_template WHERE status='ACTIVE' ORDER BY id");
    $index=0;
    while($row=$rows->fetch_assoc()) assuranceAssert($provider->result['embeddings'][$index++],$cipher->decrypt($row['encrypted_embedding']),'actual embedding preserved');
},$guidedResults);
foreach(['wrong_count','wrong_protocol','wrong_sources','synthetic_b','missing_measurements'] as $defect) {
    assuranceCase('reject guided result '.$defect,function() use($db,$defect) {
        $provider=new GuidedFixtureInference();
        if($defect==='wrong_count') array_pop($provider->result['embeddings']);
        if($defect==='wrong_protocol') unset($provider->result['pose_protocol']);
        if($defect==='wrong_sources') $provider->result['template_sources'][0]='BURST_B';
        if($defect==='synthetic_b') $provider->result['embeddings'][5]=vector128(.8);
        if($defect==='missing_measurements') unset($provider->result['quality_summary']['accepted_poses']);
        $result=guidedService($provider)->enroll(assuranceEnrollCommand(['pose_protocol'=>'guided-five-v1']));
        assuranceAssert('REJECTED',$result['state'],'untrusted guided result rejected');
        assuranceAssert(0,(int)assuranceScalar($db,'SELECT COUNT(*) FROM face_template'),'no partial write');
    },$guidedResults);
}
assuranceCase('legacy cannot persist six templates',function() use($db) {
    $provider=new GuidedFixtureInference();
    $result=guidedService($provider)->enroll(assuranceEnrollCommand());
    assuranceAssert('REJECTED',$result['state'],'legacy rejects six');
    assuranceAssert(0,(int)assuranceScalar($db,'SELECT COUNT(*) FROM face_template'),'no writes');
},$guidedResults);
assuranceCase('unsupported providers and unknown protocols never silently downgrade',function() use($db) {
    foreach(['guided-five-v1','unknown'] as $protocol) {
        $provider=new AssuranceFakeInference(validReferenceResult(),validTwoBurstResult());
        $result=guidedService($provider)->enroll(assuranceEnrollCommand(['pose_protocol'=>$protocol]));
        assuranceAssert('REJECTED',$result['state'],'unsupported guided request rejected');
        assuranceAssert(0,(int)assuranceScalar($db,'SELECT COUNT(*) FROM face_template'),'no writes');
    }
},$guidedResults);
assuranceCase('capability requires PHP provider and Python support',function() {
    assuranceAssert(true,method_exists(FaceEnrollmentService::class,'guidedEnrollmentCapability'),'PHP capability gate exists');
    $guided=new GuidedFixtureInference(); $legacy=new AssuranceFakeInference(validReferenceResult());
    $cap=['pose_protocol'=>'guided-five-v1','template_count'=>6,'windows'=>[
        'centre_yaw_max'=>.06,'centre_pitch_min'=>.35,'centre_pitch_max'=>.75,'side_yaw_min'=>.08,
        'side_yaw_max'=>.16,'pitch_tolerance'=>.08,'pitch_delta_min'=>.05,'pitch_delta_max'=>.10,'stable_ms'=>300]];
    assuranceAssert($cap,FaceEnrollmentService::guidedEnrollmentCapability($guided,$cap),'both support protocol');
    assuranceAssert(null,FaceEnrollmentService::guidedEnrollmentCapability($legacy,$cap),'old PHP cannot advertise');
    assuranceAssert(null,FaceEnrollmentService::guidedEnrollmentCapability($guided,null),'old Python cannot advertise');
    assuranceAssert(null,FaceEnrollmentService::guidedEnrollmentCapability($guided,['pose_protocol'=>'unknown']),'unknown Python protocol');
},$guidedResults);
$failures=0;
foreach($guidedResults as $r) {
    if($r['status']==='PASS') fwrite(STDOUT,'PASS '.$r['name']."\n");
    else { $failures++; fwrite(STDERR,'FAIL '.$r['name'].': '.$r['message']."\n"); }
}
fwrite(STDOUT,count($guidedResults).' FACE-ENR-09 scenarios, '.$failures." failed.\n");
exit($failures?1:0);
