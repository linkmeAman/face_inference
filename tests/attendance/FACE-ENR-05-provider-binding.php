<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string)file_get_contents($root . '/classes/services/FaceEnrollmentService.php');
$client = (string)file_get_contents($root . '/classes/clients/FaceInferenceClient.php');
$controller = (string)file_get_contents($root . '/controllers/faceEnrollment.php');

$failures = [];
function enrollmentProviderCheck(bool $condition, string $message): void
{
	global $failures;
	if (!$condition) {
		$failures[] = $message;
	}
}

foreach (['provider_id', 'provider_version', 'calibration_policy_version'] as $field) {
	enrollmentProviderCheck(strpos($client, $field) !== false, "inference adapter does not bind {$field} to enrollment output");
	enrollmentProviderCheck(strpos($service, "['{$field}']") !== false, "enrollment service does not validate {$field}");
}
enrollmentProviderCheck(strpos($service, 'PROVIDER_MODEL_MISMATCH') !== false, 'enrollment provider/model mismatch reason is missing');
enrollmentProviderCheck(strpos($service, 'model_name') !== false, 'enrollment does not validate the model version name when provided');
enrollmentProviderCheck(strpos($service, 'face_model_version') !== false, 'enrollment does not consult the model registry');
enrollmentProviderCheck(strpos($service, 'function withdraw(int $memberId, int $actorEmployeeId, int $branchId') !== false, 'revocation is not branch-scoped');
enrollmentProviderCheck(strpos($controller, '$actorId, $branchId') !== false, 'controller does not pass the authorized branch into revocation');

if ($failures) {
	fwrite(STDERR, "FAIL FACE-ENR-05 provider binding\n - " . implode("\n - ", $failures) . "\n");
	exit(1);
}

echo "PASS FACE-ENR-05 provider binding\n";
