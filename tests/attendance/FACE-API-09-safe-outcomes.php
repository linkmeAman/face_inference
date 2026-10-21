<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/classes/services/FaceAttendanceApiService.php';

$db = (new ReflectionClass(mysqli::class))->newInstanceWithoutConstructor();
$provider = new class implements FaceRecognitionProvider {
    public function checkReadiness(): bool { return true; }
    public function providerMetadata(): array { return []; }
    public function initializeScan(string $scanId, array $candidates, int $ttl): array { return []; }
    public function recognizeAttempt(string $scanId, array $frames, string $challengeToken = '', array $captureContexts = []): array { return []; }
    public function deleteScan(string $scanId): void {}
};
$service = new FaceAttendanceApiService($db, $provider, new FaceEmbeddingCipher(str_repeat('k', 32), 'test-key'), new FaceAttendanceFeatureConfig(false, true, false, false));
$prepare = (new ReflectionClass($service))->getMethod('prepareAttempt');
$prepare->setAccessible(true);
$respond = (new ReflectionClass($service))->getMethod('attemptResponse');
$respond->setAccessible(true);
$roster = ['member_id' => 11, 'contact_id' => 22, 'fullname' => 'Private Child'];
$scan = ['id' => 1, 'calibration_policy_version' => 'test'];
foreach (['UNKNOWN', 'AMBIGUOUS', 'REJECT_QUALITY', 'PAD_CHALLENGE', 'REJECT_PAD', 'REJECT_REPLAY', 'SYSTEM_NOT_READY'] as $outcome) {
    $prepared = $prepare->invoke($service, ['decision' => 'REVIEW', 'outcome' => $outcome, 'candidate_ref' => 'private-ref', 'liveness' => 'LIVE']);
    $response = $respond->invoke($service, $scan, $prepared['decision'], 'private-ref', 1, false, $roster, null, $prepared, false);
    if ($response['outcome'] !== $outcome || $response['member_id'] !== null || $response['contact_id'] !== null || $response['member_name'] !== null || $response['effect'] !== 'NONE' || $prepared['candidate_ref'] !== null) {
        fwrite(STDERR, "FAIL {$outcome} exposed an identity or selected a row\n");
        exit(1);
    }
}
echo "PASS FACE-API-09 non-accept outcomes clear identity and row selection\n";
