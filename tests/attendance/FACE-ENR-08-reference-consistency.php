<?php
declare(strict_types=1);
// Reuse the existing isolated temporary-table assurance fixtures unchanged.
define('FACE_ENR_06_CONTINUE_FOR_RETAKE_TESTS', true);
require __DIR__ . '/FACE-ENR-06-two-burst-assurance.php';

final class ReferenceSelectionInference implements FaceEnrollmentInferenceClient {
    public array $result;
    public array $received = [];
    public function __construct(array $extra) { $this->result = array_replace(validReferenceResult(), $extra); }
    public function encodeEnrollment(array $frames, array $captureContexts = []): array { return validTwoBurstResult(); }
    public function encodeReference($frames, $contexts = null): array {
        $this->received = is_array($frames) ? $frames : [$frames];
        return $this->result;
    }
}
function referenceCommand(array $extra = []): array {
    return array_replace(['member_id'=>101, 'branch_id'=>7, 'actor_employee_id'=>9001,
        'portraits'=>['first-pixels', 'second-pixels', 'third-pixels']], $extra);
}
$referenceResults = [];
assuranceCase('selected portrait and embedding retain the same frame', function () use ($db, $cipher): void {
    assuranceReset($db);
    $provider = new ReferenceSelectionInference(['selected_frame_index'=>2]);
    $service = new FaceEnrollmentService($db, $provider, $cipher);
    $result = $service->onboardReference(referenceCommand());
    assuranceAssert('ACTIVE', $result['state'], 'reference accepted');
    $row = $db->query('SELECT encrypted_portrait, encrypted_embedding FROM face_identity_reference WHERE id='.(int)$result['reference_id'])->fetch_assoc();
    assuranceAssert('third-pixels', $cipher->decryptBytes($row['encrypted_portrait']), 'stored portrait must be encoded frame');
    assuranceAssert(2, $result['selected_frame_index'] ?? null, 'browser selection index');
    assuranceAssert(vector128(1.0), $cipher->decrypt($row['encrypted_embedding']), 'embedding preserved');
}, $referenceResults);

foreach (['missing'=>[], 'negative'=>['selected_frame_index'=>-1], 'outside'=>['selected_frame_index'=>3],
          'fractional'=>['selected_frame_index'=>1.5], 'string'=>['selected_frame_index'=>'1'],
          'null'=>['selected_frame_index'=>null], 'boolean'=>['selected_frame_index'=>true]] as $name=>$extra) {
    assuranceCase('invalid selection '.$name.' writes no reference', function () use ($db, $cipher, $extra): void {
        assuranceReset($db);
        $service = new FaceEnrollmentService($db, new ReferenceSelectionInference($extra), $cipher);
        $result = $service->onboardReference(referenceCommand());
        assuranceAssert('REJECTED', $result['state'], 'invalid reference index rejected');
        assuranceAssert(0, (int)assuranceScalar($db, 'SELECT COUNT(*) FROM face_identity_reference'), 'no mismatched reference persisted');
    }, $referenceResults);
}
assuranceCase('invalid input frame is not silently filtered and renumbered', function () use ($db, $cipher): void {
    assuranceReset($db);
    $provider = new ReferenceSelectionInference(['selected_frame_index'=>1]);
    $service = new FaceEnrollmentService($db, $provider, $cipher);
    $result = $service->onboardReference(referenceCommand(['portraits'=>['first', '', 'third']]));
    assuranceAssert('REJECTED', $result['state'], 'invalid burst rejected');
    assuranceAssert([], $provider->received, 'invalid burst never sent to inference');
}, $referenceResults);
assuranceCase('legacy single frame provider remains unambiguous', function () use ($db, $cipher): void {
    assuranceReset($db);
    $service = new FaceEnrollmentService($db, new AssuranceFakeInference(validReferenceResult()), $cipher);
    $result = $service->onboardReference(referenceCommand());
    assuranceAssert('ACTIVE', $result['state'], 'legacy provider accepted');
    assuranceAssert(0, $result['selected_frame_index'] ?? null, 'legacy provider encoded first frame only');
}, $referenceResults);

$referenceFailures = 0;
foreach ($referenceResults as $result) {
    if ($result['status'] === 'PASS') fwrite(STDOUT, 'PASS '.$result['name']."\n");
    else { $referenceFailures++; fwrite(STDERR, 'FAIL '.$result['name'].': '.$result['message']."\n"); }
}
fwrite(STDOUT, count($referenceResults).' FACE-ENR-08 scenarios, '.$referenceFailures." failed.\n");
exit($referenceFailures ? 1 : 0);
