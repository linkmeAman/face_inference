<?php

header_remove('Access-Control-Allow-Origin');
header('X-Attendance-API-Version: v1');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Authentication, X-Requested-With, uid, device, bid, token');
header('Access-Control-Allow-Methods: POST, OPTIONS');
require_once dirname(__DIR__, 2) . '/classes/services/FaceAttendanceConfig.php';

$httpsRequired = class_exists('FaceAttendanceConfig') ? FaceAttendanceConfig::getFlag('FACE_ATTENDANCE_REQUIRE_HTTPS') : filter_var(getenv('FACE_ATTENDANCE_REQUIRE_HTTPS') ?: '1', FILTER_VALIDATE_BOOLEAN);
$forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$httpsActive = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || $forwardedProto === 'https';
if ($httpsRequired && PHP_SAPI !== 'cli' && !$httpsActive) {
	http_response_code(426);
	echo json_encode(['api_version' => 'v1', 'state' => 'REJECTED', 'reason' => 'HTTPS_REQUIRED', 'error' => 1, 'message' => 'HTTPS is required', 'pad_status' => 'DEFERRED', 'commit_mode' => 'TEACHER_REVIEW', 'live_write' => false], JSON_UNESCAPED_SLASHES);
	exit;
}

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$allowedOrigins = preg_split('/\s*,\s*/', FaceAttendanceConfig::getEnv('FACE_ATTENDANCE_ALLOWED_ORIGINS'), -1, PREG_SPLIT_NO_EMPTY);
if ($origin !== '') {
	if (!$allowedOrigins) {
		http_response_code(503);
		echo json_encode(['api_version' => 'v1', 'state' => 'REJECTED', 'reason' => 'ORIGIN_CONFIGURATION_MISSING', 'error' => 1, 'message' => 'Browser origin allowlist is not configured', 'pad_status' => 'DEFERRED', 'commit_mode' => 'TEACHER_REVIEW', 'live_write' => false], JSON_UNESCAPED_SLASHES);
		exit;
	}
	if (!in_array($origin, $allowedOrigins, true)) {
		http_response_code(403);
		echo json_encode(['api_version' => 'v1', 'state' => 'REJECTED', 'reason' => 'ORIGIN_NOT_ALLOWED', 'error' => 1, 'message' => 'Request origin is not allowed', 'pad_status' => 'DEFERRED', 'commit_mode' => 'TEACHER_REVIEW', 'live_write' => false], JSON_UNESCAPED_SLASHES);
		exit;
	}
	header('Access-Control-Allow-Origin: ' . $origin);
	header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
	http_response_code(204);
	exit;
}
if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	http_response_code(405);
	header('Allow: POST, OPTIONS');
	echo json_encode(['api_version' => 'v1', 'state' => 'REJECTED', 'reason' => 'METHOD_NOT_ALLOWED', 'error' => 1, 'message' => 'Use POST for face attendance operations', 'pad_status' => 'DEFERRED', 'commit_mode' => 'TEACHER_REVIEW', 'live_write' => false], JSON_UNESCAPED_SLASHES);
	exit;
}

$request = json_decode(file_get_contents('php://input'), true);
$request = is_array($request) ? $request : (is_array($_POST) ? $_POST : []);
$operation = strtolower((string)($_GET['operation'] ?? ($request['operation'] ?? '')));
$actions = [
	'readiness' => 'getFaceReadiness',
	'start' => 'startFaceScan',
	'nonce' => 'getCaptureNonce',
	'renew' => 'renewFaceScan',
	'attempt' => 'recognizeFaceAttempt',
	'status' => 'getFaceScanStatus',
	'complete' => 'completeFaceScan',
	'cancel' => 'cancelFaceScan',
	'reset-test-data' => 'resetFaceAttendanceTestData',
	'enroll' => 'enrollFace',
	'revoke' => 'withdrawFace',
];
$legacyAction = (string)($_GET['action'] ?? '');
$operationByAction = array_flip($actions);
if ($operation === '' && isset($operationByAction[$legacyAction])) {
	$operation = $operationByAction[$legacyAction];
}
$action = $actions[$operation] ?? '';

if ($operation === 'attempt' && isset($_FILES['frames'])) {
	$request['frames'] = [];
	$files = $_FILES['frames'];
	$tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
	foreach ($tmpNames as $tmpName) {
		if (is_string($tmpName) && is_uploaded_file($tmpName)) {
			$frame = file_get_contents($tmpName);
			if (is_string($frame)) {
				$request['frames'][] = $frame;
			}
		}
	}
}

if ($operation === 'enroll' && isset($_FILES['frames'])) {
	$request['frames'] = [];
	$files = $_FILES['frames'];
	$tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
	foreach ($tmpNames as $tmpName) {
		if (is_string($tmpName) && is_uploaded_file($tmpName)) {
			$frame = file_get_contents($tmpName);
			if (is_string($frame)) {
				$request['frames'][] = $frame;
			}
		}
	}
}

if ($action === '') {
	http_response_code(404);
	echo json_encode([
		'api_version' => 'v1',
		'state' => 'REJECTED',
		'reason' => 'BAD_OPERATION',
		'error' => 1,
		'message' => 'Unknown face attendance operation',
		'pad_status' => 'DEFERRED',
		'commit_mode' => 'TEACHER_REVIEW',
		'live_write' => false,
	], JSON_UNESCAPED_SLASHES);
	exit;
}

// Load application controllers only after transport and operation validation.
// Rejected requests must not enter legacy bootstrap code or lose their
// versioned JSON error if an application dependency is unavailable.
require_once dirname(__DIR__, 2) . '/controllers/faceAttendance.php';
require_once dirname(__DIR__, 2) . '/controllers/faceEnrollment.php';

$controller = in_array($operation, ['enroll', 'revoke'], true)
	? new faceEnrollment
	: new faceAttendance;
echo $controller->handle($action, $request);
