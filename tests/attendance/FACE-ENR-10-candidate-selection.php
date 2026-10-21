<?php
declare(strict_types=1);
require __DIR__ . '/../../classes/services/FaceEnrollmentService.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function invokeEnrollment(string $name, array $arg): mixed {
    $service = (new ReflectionClass(FaceEnrollmentService::class))->newInstanceWithoutConstructor();
    if ($name === 'normalizeCommand') (new ReflectionProperty($service, 'maxFrameBytes'))->setValue($service, 1000000);
    $method = new ReflectionMethod($service, $name);
    return $method->invoke($service, $arg);
}
class SelectionFixtureProvider implements FaceEnrollmentInferenceClient {
    public function encodeEnrollment(array $frames, array $captureContexts = []): array { return []; }
    public function encodeEnrollmentV2(array $a, array $b, array $ref, array $ca=[], array $cb=[], ?string $protocol=null): array { return []; }
    public function supportsEnrollmentProtocol(string $protocol): bool { return $protocol === 'guided-five-select-v1'; }
}

$cap = ['pose_protocol'=>'guided-five-select-v1','template_count'=>6,'burst_a_count'=>10,'burst_b_count'=>3,'samples_per_pose'=>2,
    'windows'=>['centre_yaw_max'=>.06,'centre_pitch_min'=>.35,'centre_pitch_max'=>.75,
        'side_yaw_min'=>.08,'side_yaw_max'=>.16,'pitch_tolerance'=>.08,
        'pitch_delta_min'=>.05,'pitch_delta_max'=>.10,'stable_ms'=>300]];
check(FaceEnrollmentService::guidedEnrollmentCapability(new SelectionFixtureProvider(),$cap)===$cap,'PHP must advertise supported new protocol');
echo "PASS new capability gated by PHP support\n";
$command=['pose_protocol'=>'guided-five-select-v1','burst_a'=>array_fill(0,10,'frame'),
    'burst_b'=>array_fill(0,3,'frame'),'branch_id'=>7,'member_id'=>101,'actor_employee_id'=>9001,
    'notice_version'=>'test','purpose'=>'test','teacher_confirmed_member'=>true];
$normalized=invokeEnrollment('normalizeCommand',$command);
check(count($normalized['burst_a'])===10,'PHP must accept ten Burst A candidates');
echo "PASS ten plus three input contract\n";
$oversize=$command;
$oversize['burst_a']=array_fill(0,10,str_repeat('x',800000));
$oversize['burst_b']=array_fill(0,3,str_repeat('x',800000));
try { invokeEnrollment('normalizeCommand',$oversize); throw new RuntimeException('oversize enrollment accepted'); }
catch (FaceEnrollmentException $e) { check($e->reason==='ENROLLMENT_PAYLOAD_TOO_LARGE','wrong size rejection'); }
echo "PASS aggregate payload cap\n";
$a=[]; for($i=0;$i<10;$i++) $a[]=[$i+.1];
$b=[[10.1],[11.1],[12.1]];
$selected=[0,2,4,6,8];
$poses=[];
foreach(['centre','left','right','up','down'] as $pose) foreach([0,1] as $repeat) {
    $poses[]=['burst'=>'BURST_A','index'=>count($poses),'pose'=>$pose,'yaw'=>0.0,'pitch'=>.55];
}
foreach([0,1,2] as $i) $poses[]=['burst'=>'BURST_B','index'=>$i,'pose'=>'centre','yaw'=>0.0,'pitch'=>.55];
$result=['pose_protocol'=>'guided-five-select-v1','embeddings'=>[$a[0],$a[2],$a[4],$a[6],$a[8],$b[1]],
    'burst_a_embeddings'=>$a,'burst_b_embeddings'=>$b,'selected_burst_a_indices'=>$selected,
    'burst_b_template_index'=>1,'template_sources'=>['BURST_A','BURST_A','BURST_A','BURST_A','BURST_A','BURST_B'],
    'quality_summary'=>['accepted_poses'=>$poses]];
invokeEnrollment('validateGuidedResult',$result);
echo "PASS six actual indexed embeddings\n";
$result['embeddings'][0]=[999];
try { invokeEnrollment('validateGuidedResult',$result); throw new RuntimeException('synthetic embedding accepted'); }
catch (FaceEnrollmentException $e) { check($e->reason==='INVALID_INFERENCE_RESULT','wrong rejection'); }
echo "PASS synthetic embedding rejected\n";
