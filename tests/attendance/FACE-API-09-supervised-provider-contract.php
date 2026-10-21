<?php

declare(strict_types=1);

/**
 * FACE-API-09 supervised, model-independent recognition contract.
 *
 * The production scan lifecycle may select a roster row, but it must never
 * commit attendance or membership changes. Recognition providers expose a
 * stable contract and scans load templates only for that provider's model.
 */

$root = dirname(__DIR__, 2);
$servicePath = $root . '/classes/services/FaceAttendanceApiService.php';
$providerPath = $root . '/classes/contracts/FaceRecognitionProvider.php';
$clientPath = $root . '/classes/clients/FaceInferenceClient.php';
$controllerPath = $root . '/controllers/faceAttendance.php';
$migrationPath = $root . '/database/migrations/20260921_01_supervised_face_scan_contract.sql';
$v1RoutePath = $root . '/routes/v1/face-attendance.php';
$webClientPath = $root . '/pf/js/controllers/batchAttendanceController.js';
$contractPath = $root . '/docs/api/face-attendance-v1.json';
$factoryPath = $root . '/classes/providers/FaceRecognitionProviderFactory.php';
$readinessPath = $root . '/tests/attendance/FACE-SETUP-01-enrollment-readiness.php';
$sessionPath = $root . '/classes/session.class.php';
$sessionSource = is_file($sessionPath) ? (string)file_get_contents($sessionPath) : '';

$failures = [];
function faceApi09Check(bool $condition, string $message): void
{
	global $failures;
	if (!$condition) {
		$failures[] = $message;
	}
}

faceApi09Check(is_file($providerPath), 'recognition provider contract is missing');
faceApi09Check(is_file($migrationPath), 'scan provider metadata migration is missing');
faceApi09Check(is_file($v1RoutePath), 'shared v1 HTTP route is missing');
faceApi09Check(is_file($contractPath), 'machine-readable v1 contract is missing');
faceApi09Check(is_file($factoryPath), 'recognition provider factory is missing');
faceApi09Check(is_file($readinessPath), 'read-only pilot readiness verifier is missing');

$service = is_file($servicePath) ? (string)file_get_contents($servicePath) : '';
$provider = is_file($providerPath) ? (string)file_get_contents($providerPath) : '';
$client = is_file($clientPath) ? (string)file_get_contents($clientPath) : '';
$controller = is_file($controllerPath) ? (string)file_get_contents($controllerPath) : '';
$migration = is_file($migrationPath) ? (string)file_get_contents($migrationPath) : '';
$v1Route = is_file($v1RoutePath) ? (string)file_get_contents($v1RoutePath) : '';
$webClient = is_file($webClientPath) ? (string)file_get_contents($webClientPath) : '';
$contract = is_file($contractPath) ? json_decode((string)file_get_contents($contractPath), true) : null;
$factory = is_file($factoryPath) ? (string)file_get_contents($factoryPath) : '';
$enrollmentController = (string)file_get_contents($root . '/controllers/faceEnrollment.php');
$readiness = is_file($readinessPath) ? (string)file_get_contents($readinessPath) : '';

faceApi09Check(strpos($provider, 'interface FaceRecognitionProvider') !== false, 'stable provider interface is missing');
foreach (['providerMetadata', 'initializeScan', 'recognizeAttempt', 'deleteScan'] as $method) {
	faceApi09Check(strpos($provider, 'function ' . $method . '(') !== false, "provider method {$method} is missing");
}
faceApi09Check(strpos($client, 'implements FaceEnrollmentInferenceClient, FaceRecognitionProvider') !== false, 'current ONNX sidecar adapter does not implement the provider interface');
faceApi09Check(strpos($factory, 'FACE_RECOGNITION_PROVIDER_CLASS') !== false, 'provider factory is not configurable');
faceApi09Check(strpos($controller, 'new FaceInferenceClient') === false, 'attendance controller constructs the current provider directly');
faceApi09Check(strpos($enrollmentController, 'new FaceInferenceClient') === false, 'enrollment controller constructs the current provider directly');

faceApi09Check(strpos($service, 'recordAcceptedFaceAttendance(') !== false, 'scan lifecycle does not have the configured automatic attendance writer');
faceApi09Check(strpos($service, 'recordMemberAttendance(') !== false, 'scan lifecycle does not use AttendanceDomainService');
faceApi09Check(strpos($service, "'attendance_state' => \$attendanceState") !== false, 'accepted recognition does not expose automatic attendance state');
faceApi09Check(strpos($service, "'ATTENDANCE_RECORDED'") !== false && strpos($service, "'ALREADY_PRESENT'") !== false, 'accepted recognition does not expose automatic attendance effects');
faceApi09Check(strpos($service, "'pad_status' => 'DEFERRED'") !== false, 'PAD is not explicitly reported as deferred');
faceApi09Check(strpos($service, "'api_version' => 'v1'") !== false, 'scan responses do not declare API v1');

faceApi09Check(strpos($service, 'ft.model_version_id =') !== false, 'template loading is not constrained by model version');
faceApi09Check(strpos($service, 'fmv.embedding_dimension = ft.embedding_dimension') !== false, 'template loading is not constrained by registry dimension');
faceApi09Check(strpos($service, "providerMetadata()") !== false, 'scan lifecycle does not bind the provider metadata');
faceApi09Check(strpos($service, "'model_name'") !== false, 'scan lifecycle does not bind the model version name to the registry');
faceApi09Check(strpos($service, 'FaceRecognitionProvider $inference') !== false, 'scan lifecycle constructor is not typed to the provider interface');
foreach (['provider_id', 'provider_version', 'model_version_id', 'calibration_policy_version'] as $column) {
	faceApi09Check(strpos($migration, '`' . $column . '`') !== false, "attendance_scan.{$column} migration is missing");
}
faceApi09Check(substr_count($migration, '`provider_id`') >= 2, 'model registry provider_id migration is missing');
faceApi09Check(substr_count($migration, '`provider_version`') >= 2, 'model registry provider_version migration is missing');
faceApi09Check(strpos($service, 'validateProviderModel(') !== false, 'provider metadata is not verified against the model registry');

faceApi09Check(strpos($controller, "case 'getFaceScanStatus':") !== false, 'status/resume lifecycle action is missing');
faceApi09Check(strpos($service, 'function getFaceScanStatus(') !== false, 'status/resume service method is missing');
faceApi09Check(strpos($service, "'selected' =>") !== false, 'status/resume does not return server-authoritative selected members');
faceApi09Check(strpos($service, "'IDEMPOTENCY_CONFLICT'") !== false, 'cross-scan idempotency-key conflicts are not rejected');
faceApi09Check(strpos($service, "'face_attempt_'") !== false, 'idempotency keys are not serialized across concurrent scans');
faceApi09Check(strpos($service, "'member_already_selected'") !== false, 'duplicate recognized members are not represented server-side');
foreach (['start', 'attempt', 'status', 'complete', 'cancel', 'enroll', 'revoke'] as $operation) {
	faceApi09Check(strpos($v1Route, "'{$operation}'") !== false, "v1 {$operation} operation is missing");
}
faceApi09Check(strpos($v1Route, 'X-Attendance-API-Version: v1') !== false, 'v1 route does not declare its contract version');
faceApi09Check(strpos($v1Route, 'FACE_ATTENDANCE_REQUIRE_HTTPS') !== false, 'v1 route does not enforce configurable HTTPS');
faceApi09Check(strpos($v1Route, 'FACE_ATTENDANCE_ALLOWED_ORIGINS') !== false, 'v1 route does not enforce a restricted origin allowlist');
faceApi09Check(strpos($v1Route, "FACE_ATTENDANCE_REQUIRE_HTTPS') ?: '1'") !== false, 'v1 route does not fail closed to HTTPS when configuration is absent');
faceApi09Check(strpos($v1Route, 'ORIGIN_CONFIGURATION_MISSING') !== false, 'v1 route does not fail closed when browser origin configuration is absent');
faceApi09Check(strpos($v1Route, "HTTP_HOST']))") === false, 'v1 route still synthesizes an origin allowlist from the request host');
faceApi09Check(strpos($v1Route, "header_remove('Access-Control-Allow-Origin')") !== false, 'v1 route leaves the legacy wildcard origin in place');
$httpsGatePosition = strpos($v1Route, 'HTTPS_REQUIRED');
$controllerLoadPosition = strpos($v1Route, 'controllers/faceAttendance.php');
faceApi09Check($httpsGatePosition !== false && $controllerLoadPosition !== false && $httpsGatePosition < $controllerLoadPosition, 'v1 route loads application controllers before enforcing transport policy');
faceApi09Check(strpos($sessionSource, "__DIR__ . '/dbconnection.class.php'") !== false, 'session bootstrap uses a CWD-relative database include');
faceApi09Check(strpos($sessionSource, "dirname(__DIR__) . '/inc/defaults.php'") !== false, 'session bootstrap uses a CWD-relative defaults include');
faceApi09Check(substr_count($webClient, "'v1/face-attendance.php'") >= 4, 'web scan lifecycle is not using the shared v1 route');
faceApi09Check(is_array($contract), 'machine-readable v1 contract is invalid JSON');
faceApi09Check(($contract['contract_version'] ?? '') === 'v1', 'machine-readable contract version is not v1');
faceApi09Check(($contract['commit_modes'] ?? []) === ['TEACHER_REVIEW', 'AUTO_PRESENT'], 'contract does not expose configured commit modes');
faceApi09Check(($contract['pad_status'] ?? '') === 'DEFERRED', 'contract does not declare PAD deferred');
foreach (['start', 'attempt', 'status', 'complete', 'cancel', 'enroll', 'revoke'] as $operation) {
	faceApi09Check(isset($contract['operations'][$operation]), "machine-readable {$operation} contract is missing");
}
faceApi09Check(in_array('SELECT_PRESENT', $contract['effects'] ?? [], true), 'contract does not define SELECT_PRESENT');
faceApi09Check(in_array('ATTENDANCE_RECORDED', $contract['effects'] ?? [], true), 'contract does not define ATTENDANCE_RECORDED');
faceApi09Check(in_array('ALREADY_PRESENT', $contract['effects'] ?? [], true), 'contract does not define ALREADY_PRESENT');
faceApi09Check(in_array('selected', $contract['operations']['status']['returns'] ?? [], true), 'status contract cannot reconstruct selected rows');
faceApi09Check(strpos($readiness, "'provider_id'") !== false && strpos($readiness, "'provider_version'") !== false && strpos($readiness, "'calibration_policy_version'") !== false, 'readiness verifier does not require provider-bound scan columns');
faceApi09Check(strpos($readiness, 'PROVIDER_MODEL_MISMATCH') !== false, 'readiness verifier does not validate the configured provider/model tuple');
faceApi09Check(strpos($readiness, 'PAD DEFERRED') !== false, 'readiness verifier does not report PAD as deferred');

if ($failures) {
	fwrite(STDERR, "FAIL FACE-API-09 supervised provider contract\n - " . implode("\n - ", $failures) . "\n");
	exit(1);
}

echo "PASS FACE-API-09 supervised provider contract\n";
