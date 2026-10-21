<?php
declare(strict_types=1);
define('FACE_ENR_06_CONTINUE_FOR_RETAKE_TESTS', true);
require __DIR__ . '/FACE-ENR-06-two-burst-assurance.php';

final class SelectionPersistenceInference implements FaceEnrollmentInferenceClient {
    public array $result;
    public function __construct() {
        $this->result = validTwoBurstResult();
        $vectors = [];
        for ($i = 0; $i < 13; $i++) $vectors[] = vector128(1.0);
        $selected = [0,2,4,6,8];
        $this->result['pose_protocol'] = 'guided-five-select-v1';
        $this->result['burst_a_embeddings'] = array_slice($vectors,0,10);
        $this->result['burst_b_embeddings'] = array_slice($vectors,10,3);
        $this->result['selected_burst_a_indices'] = $selected;
        $this->result['burst_b_template_index'] = 1;
        $this->result['embeddings'] = array_merge(array_map(fn($i) => $vectors[$i], $selected), [$vectors[11]]);
        $this->result['template_sources'] = ['BURST_A','BURST_A','BURST_A','BURST_A','BURST_A','BURST_B'];
        $this->result['burst_a_count'] = 10;
        $this->result['quality_summary']['frames_accepted'] = 13;
        $this->result['evidences']['burst_a'] = sampleEvidence(10, 'BURST_A');
        $poses = [];
        foreach (['centre','left','right','up','down'] as $pose) foreach ([0,1] as $repeat) {
            $poses[] = ['burst'=>'BURST_A','index'=>count($poses),'pose'=>$pose,'yaw'=>0.0,'pitch'=>.55];
        }
        foreach ([0,1,2] as $i) $poses[] = ['burst'=>'BURST_B','index'=>$i,'pose'=>'centre','yaw'=>0.0,'pitch'=>.55];
        $this->result['quality_summary']['accepted_poses'] = $poses;
    }
    public function encodeReference(string $frame): array { return validReferenceResult(); }
    public function encodeEnrollment(array $frames, array $captureContexts=[]): array { return $this->result; }
    public function encodeEnrollmentV2(array $a, array $b, array $ref, array $ca=[], array $cb=[], ?string $protocol=null): array {
        if (count($a)!==10 || count($b)!==3 || $protocol!=='guided-five-select-v1') throw new RuntimeException('Wrong enrollment request');
        return $this->result;
    }
    public function supportsEnrollmentProtocol(string $protocol): bool { return $protocol==='guided-five-select-v1'; }
}
function selectionService(SelectionPersistenceInference $provider): FaceEnrollmentService {
    global $db,$cipher;
    assuranceReset($db);
    $service=new FaceEnrollmentService($db,$provider,$cipher);
    $reference=$service->onboardReference(['branch_id'=>7,'member_id'=>101,'actor_employee_id'=>9001,'portrait'=>'reference']);
    assuranceAssert('ACTIVE',$reference['state'],'fixture reference');
    return $service;
}
$selectionResults=[];
assuranceCase('ten candidate frames persist only six selected templates', function() use($db) {
    $provider=new SelectionPersistenceInference();
    $service=selectionService($provider);
    $command=assuranceEnrollCommand(['pose_protocol'=>'guided-five-select-v1','burst_a'=>assuranceFrames(10)]);
    $result=$service->enroll($command);
    assuranceAssert('ACTIVE',$result['state'],'selected enrollment state');
    assuranceAssert(6,(int)assuranceScalar($db,"SELECT COUNT(*) FROM face_template WHERE status='ACTIVE'"),'active template count');
    assuranceAssert(10,(int)assuranceScalar($db,"SELECT burst_a_count FROM face_enrollment_attempt ORDER BY id DESC LIMIT 1"),'candidate count recorded');
},$selectionResults);
assuranceCase('synthetic selected template rolls back without partial enrollment', function() use($db) {
    $provider=new SelectionPersistenceInference();
    $provider->result['embeddings'][0]=vector128(.8);
    $service=selectionService($provider);
    $result=$service->enroll(assuranceEnrollCommand(['pose_protocol'=>'guided-five-select-v1','burst_a'=>assuranceFrames(10)]));
    assuranceAssert('REJECTED',$result['state'],'invalid provenance rejected');
    assuranceAssert(0,(int)assuranceScalar($db,'SELECT COUNT(*) FROM face_template'),'no partial template writes');
},$selectionResults);
$failed=0;
foreach($selectionResults as $result){
    if($result['status']==='PASS') echo 'PASS '.$result['name']."\n";
    else { $failed++; fwrite(STDERR,'FAIL '.$result['name'].': '.$result['message']."\n"); }
}
echo count($selectionResults).' FACE-ENR-11 scenarios, '.$failed." failed.\n";
exit($failed?1:0);
