<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/classes/services/FaceEnrollmentService.php';

final class EarlyIdentityFixture extends FaceEnrollmentService
{
    public array $observations = [];
    public object $policyClient;

    public function __construct()
    {
        (new ReflectionProperty(FaceEnrollmentService::class, 'maxFrameBytes'))->setValue($this, 1000000);
        (new ReflectionProperty(FaceEnrollmentService::class, 'duplicateThreshold'))->setValue($this, 0.90);
        $this->policyClient = new class implements FaceEnrollmentInferenceClient {
            public bool $available = true;
            public function encodeEnrollment(array $frames, array $captureContexts = []): array { return []; }
            public function identityPolicy(): ?array {
                if (!$this->available) return null;
                return ['policy_version'=>'test-policy-v1', 'review_threshold'=>0.75,
                    'duplicate_block_threshold'=>0.90, 'min_template_support'=>2, 'min_margin'=>0.20];
            }
        };
        (new ReflectionProperty(FaceEnrollmentService::class, 'inference'))->setValue($this, $this->policyClient);
    }

    public function checkPreviewCollision(string $frame, int $branchId, int $contactId, ?array $captureContext = null, ?array $captureCommand = null): array
    {
        $next = array_shift($this->observations);
        (new ReflectionProperty(FaceEnrollmentService::class, 'lastPreviewInference'))->setValue($this,
            ['selected_face_index'=>0, 'faces'=>[['quality'=>[
                'accepted'=>true, 'pose_yaw'=>0.01, 'pose_pitch'=>0.5]]]]);
        return $next;
    }
}

$service = new EarlyIdentityFixture();
$command = ['frames'=>['fresh-frontal-one','fresh-frontal-two'],
    'capture_contexts'=>[['frame_width'=>640], ['frame_width'=>640]],
    'contact_id'=>101, 'branch_id'=>2, 'capture_session_token'=>'fixture'];
$blocked = ['duplicate_band'=>'REVIEW','conflicting_contact_id'=>202,
    'conflicting_member_id'=>303,'conflicting_member_name'=>'Other child',
    'candidate_contact_id'=>202,'candidate_member_id'=>303,'candidate_member_name'=>'Other child',
    'has_enrolled_templates'=>true,'template_support'=>2,'score'=>0.82];
$proven = array_replace($blocked, ['duplicate_band'=>'BLOCKED', 'score'=>0.91]);
$review = ['duplicate_band'=>'REVIEW','conflicting_contact_id'=>202];
$clear = ['duplicate_band'=>'CLEAR'];

$cases = [
    // Scores in the review band stay advisory even across two frames.
    [['state'=>'POSSIBLE_SIMILARITY'], [$blocked, $blocked]],
    [['state'=>'CONFIRMED_DUPLICATE'], [$proven, $proven]],
    [['state'=>'POSSIBLE_SIMILARITY'], [array_replace($proven, ['margin'=>0.10]), array_replace($proven, ['margin'=>0.10])]],
    [['state'=>'POSSIBLE_SIMILARITY'], [$blocked, $clear]],
    [['state'=>'POSSIBLE_SIMILARITY'], [$blocked, array_replace($blocked, ['template_support'=>1])]],
    [['state'=>'POSSIBLE_SIMILARITY'], [$review, $clear]],
    [['state'=>'POSSIBLE_SIMILARITY'], [$blocked, array_replace($blocked, [
        'conflicting_contact_id'=>204, 'candidate_contact_id'=>204
    ])]],
    // A reference-only match remains visible, but cannot satisfy the
    // enrolled-template evidence required for a hard early stop.
    [['state'=>'POSSIBLE_SIMILARITY'], [array_replace($proven, ['has_enrolled_templates'=>false]), array_replace($proven, ['has_enrolled_templates'=>false])]],
    [['state'=>'CLEAR'], [$clear, $clear]],
    [['state'=>'CHECK_UNAVAILABLE'], [$clear, ['duplicate_band'=>'UNAVAILABLE']]],
    [['state'=>'CHECK_UNAVAILABLE'], [$proven, ['duplicate_band'=>'UNAVAILABLE']]],
];
foreach ($cases as [$expected, $observations]) {
    $service->observations = $observations;
    $actual = $service->checkEarlyEnrollmentIdentity($command);
    if (($actual['state'] ?? '') !== $expected['state']) {
        throw new RuntimeException('Early identity decision mismatch: ' . json_encode($actual));
    }
}
$sameFrame = $command;
$sameFrame['frames'][1] = $sameFrame['frames'][0];
if ($service->checkEarlyEnrollmentIdentity($sameFrame)['state'] !== 'CHECK_UNAVAILABLE') {
    throw new RuntimeException('Repeated frame was accepted as independent evidence');
}
$service->policyClient->available = false;
if ($service->checkEarlyEnrollmentIdentity($command)['state'] !== 'CHECK_UNAVAILABLE') {
    throw new RuntimeException('Missing runtime duplicate policy did not fail safely');
}
echo "FACE-ENR-13 early identity PASS\n";
