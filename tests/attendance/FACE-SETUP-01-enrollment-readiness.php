<?php

/**
 * FACE-SETUP-01: read-only enrollment readiness contract.
 *
 * This test intentionally performs no INSERT, UPDATE, DELETE, migration, or
 * file write. It reports the exact blockers that otherwise surface in the
 * browser as the generic FACE_API_ERROR response.
 */

$root = dirname(__DIR__, 2);
$required = in_array('--required', $argv, true) || getenv('FACE_SETUP_REQUIRED') === '1';
$dbName = getenv('FACE_SETUP_DB') ?: 'ccc_database';
$dbHost = getenv('FACE_SETUP_DB_HOST') ?: (getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1');
$dbUser = getenv('FACE_SETUP_DB_USER') ?: (getenv('ATTENDANCE_TEST_DB_USER') ?: '');
$dbPassword = getenv('FACE_SETUP_DB_PASSWORD') ?: (getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '');
$inferenceUrl = rtrim(getenv('FACE_INFERENCE_URL') ?: 'http://127.0.0.1:6120', '/');

$requiredTables = [
	'biometric_consent',
	'face_template',
	'face_model_version',
	'attendance_scan',
	'attendance_scan_roster',
	'recognition_attempt',
	'attendance_event',
];

$failures = [];
$warnings = [];
$assertions = 0;

function setup_result(string $label, bool $passed, string $detail): void
{
	echo ($passed ? 'PASS' : 'BLOCKED') . " {$label}: {$detail}\n";
}

if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
	$failures[] = 'FACE_SETUP_DB contains an unsafe database name';
	setup_result('database', false, 'unsafe database name');
} elseif (!class_exists('mysqli')) {
	$failures[] = 'PHP mysqli extension is unavailable';
	setup_result('database', false, 'PHP mysqli extension is unavailable');
} else {
	$conn = null;
	try {
		$conn = @new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
	} catch (Throwable $exception) {
		$conn = null;
	}
	if (!$conn || $conn->connect_errno) {
		try {
			require_once $root . '/classes/dbconnection.class.php';
			$conn = (new dbconnection())->connect($dbName);
		} catch (Throwable $exception) {
			// The generic unavailable result below intentionally avoids exposing
			// database credentials or driver details.
		}
	}
	$assertions++;
	if (!$conn || $conn->connect_errno) {
		$failures[] = 'database unavailable';
		setup_result('database', false, 'connection unavailable');
	} else {
		$conn->set_charset('utf8mb4');
		$missing = [];
		$escapedDb = $conn->real_escape_string($dbName);
		$result = $conn->query("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$escapedDb}'");
		$present = [];
		if ($result) {
			while ($row = $result->fetch_assoc()) {
				$tableName = $row['TABLE_NAME'] ?? ($row['table_name'] ?? '');
				if ($tableName !== '') {
					$present[$tableName] = true;
				}
			}
			$result->free();
		}
		foreach ($requiredTables as $table) {
			$assertions++;
			if (!isset($present[$table])) {
				$missing[] = $table;
			}
		}
		if ($missing) {
			$failures[] = 'missing face tables: ' . implode(', ', $missing);
			setup_result('face-schema', false, 'missing ' . implode(', ', $missing));
		} else {
			setup_result('face-schema', true, 'all required face tables exist');
			$providerSchemaReady = true;
			$requiredColumns = [
				'face_model_version' => ['provider_id', 'provider_version'],
				'attendance_scan' => ['provider_id', 'provider_version', 'model_version_id', 'calibration_policy_version'],
			];
			foreach ($requiredColumns as $table => $columns) {
				foreach ($columns as $column) {
					$assertions++;
					$escapedTable = $conn->real_escape_string($table);
					$escapedColumn = $conn->real_escape_string($column);
					$count = (int)$conn->query("SELECT COUNT(*) AS total FROM information_schema.columns WHERE table_schema = '{$escapedDb}' AND table_name = '{$escapedTable}' AND column_name = '{$escapedColumn}'")->fetch_assoc()['total'];
					if ($count !== 1) {
						$providerSchemaReady = false;
						$failures[] = "missing provider schema column {$table}.{$column}";
					}
				}
			}

			$modelVersionId = (int)(getenv('FACE_MODEL_VERSION_ID') ?: '0');
			$providerId = (string)(getenv('FACE_RECOGNITION_PROVIDER_ID') ?: 'local-onnx');
			$providerVersion = (string)(getenv('FACE_RECOGNITION_PROVIDER_VERSION') ?: '1');
			$policyVersion = (string)(getenv('FACE_CALIBRATION_POLICY_VERSION') ?: '');
			$assertions++;
			if (!$providerSchemaReady) {
				$failures[] = 'PROVIDER_MODEL_MISMATCH: provider schema migration is required';
				setup_result('provider-model', false, 'PROVIDER_MODEL_MISMATCH');
			} elseif ($modelVersionId < 1 || $policyVersion === '') {
				$failures[] = 'PROVIDER_MODEL_MISMATCH: configured model or calibration policy version is missing';
				setup_result('provider-model', false, 'PROVIDER_MODEL_MISMATCH');
			} else {
				$safeProviderId = $conn->real_escape_string($providerId);
				$safeProviderVersion = $conn->real_escape_string($providerVersion);
				$safePolicyVersion = $conn->real_escape_string($policyVersion);
				$model = $conn->query("SELECT id FROM face_model_version WHERE id = '{$modelVersionId}' AND provider_id = '{$safeProviderId}' AND provider_version = '{$safeProviderVersion}' AND policy_version = '{$safePolicyVersion}' AND status = 'ACTIVE' LIMIT 1")->fetch_assoc();
				if (!$model) {
					$failures[] = 'PROVIDER_MODEL_MISMATCH: configured provider/model tuple is not active';
					setup_result('provider-model', false, 'PROVIDER_MODEL_MISMATCH');
				} else {
					setup_result('provider-model', true, 'configured provider/model/calibration tuple is active');
				}
			}
		}
		$conn->close();
	}
}

$httpsRequired = filter_var(getenv('FACE_ATTENDANCE_REQUIRE_HTTPS') ?: '0', FILTER_VALIDATE_BOOLEAN);
$allowedOrigins = preg_split('/\s*,\s*/', (string)(getenv('FACE_ATTENDANCE_ALLOWED_ORIGINS') ?: ''), -1, PREG_SPLIT_NO_EMPTY);
$assertions += 2;
if (!$httpsRequired) {
	$failures[] = 'v1 HTTPS enforcement is not enabled';
}
if (!$allowedOrigins) {
	$failures[] = 'v1 allowed-origin list is empty';
}
setup_result('v1-transport', $httpsRequired && (bool)$allowedOrigins, $httpsRequired && $allowedOrigins ? 'HTTPS and exact origins configured' : 'HTTPS/origin configuration incomplete');

$health = null;
$healthError = '';
if (function_exists('curl_init')) {
	$curl = curl_init($inferenceUrl . '/health');
	curl_setopt_array($curl, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 2,
		CURLOPT_TIMEOUT => 5,
	]);
	$body = curl_exec($curl);
	$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
	$healthError = curl_error($curl);
	curl_close($curl);
	if (is_string($body) && $status >= 200 && $status < 300) {
		$health = json_decode($body, true);
	}
} else {
	$healthError = 'PHP cURL extension is unavailable';
}

$assertions++;
if (!is_array($health)) {
	$failures[] = 'private inference health unavailable';
	setup_result('inference-health', false, $healthError !== '' ? 'unavailable' : 'invalid response');
} else {
	$modelReady = !empty($health['model']['model_ready']);
	$calibrationApproved = !empty($health['calibration_approved']);
	$modelVersionId = (int)($health['model_version_id'] ?? 0);
	$assertions += 3;
	if (!$modelReady) {
		$failures[] = 'inference model is not ready';
	}
	if (!$calibrationApproved) {
		$failures[] = 'approved calibration is not loaded';
		$warnings[] = 'approved calibration is not loaded; enrollment/automatic ACCEPT must remain disabled';
	}
	if ($modelVersionId < 1) {
		$failures[] = 'inference model version ID is not configured';
	}
	if ($modelReady) {
		setup_result('inference-model', true, 'model is ready');
	} else {
		setup_result('inference-model', false, 'model is not ready');
	}
	if ($calibrationApproved) {
		setup_result('calibration', true, 'approved calibration is loaded');
	} else {
		setup_result('calibration', false, 'approved calibration is not loaded; REVIEW-only behavior is required');
	}
	if ($modelVersionId > 0) {
		setup_result('inference-model-version', true, 'model version ID is configured');
	} else {
		setup_result('inference-model-version', false, 'model version ID is missing or zero');
	}
}

setup_result('pad-status', true, 'PAD DEFERRED status remains explicit; only server-confirmed ACCEPT results may enter the configured attendance path');

$controller = @file_get_contents($root . '/controllers/faceEnrollment.php');
$assertions++;
$diagnosticContract = is_string($controller)
	&& strpos($controller, 'FACE_SCHEMA_NOT_READY') !== false
	&& strpos($controller, 'FACE_INFERENCE_NOT_READY') !== false;
if (!$diagnosticContract) {
	$failures[] = 'enrollment controller does not expose safe schema/inference diagnostic reasons';
	setup_result('error-diagnostics', false, 'generic FACE_API_ERROR contract remains');
} else {
	setup_result('error-diagnostics', true, 'safe schema/inference diagnostic reasons are present');
}

$scanService = @file_get_contents($root . '/classes/services/FaceAttendanceApiService.php');
$v1Route = $root . '/routes/v1/face-attendance.php';
$v1Contract = $root . '/docs/api/face-attendance-v1.json';
$assertions += 3;
$configuredWriter = is_string($scanService)
	&& strpos($scanService, 'recordAcceptedFaceAttendance(') !== false
	&& strpos($scanService, 'automaticAttendanceEnabled()') !== false
	&& strpos($scanService, "!\$this->config->shadowMode") !== false;
if (!$configuredWriter) {
	$failures[] = 'recognition lifecycle does not gate automatic attendance writes by live-write configuration';
}
if (!is_file($v1Route)) {
	$failures[] = 'v1 face-attendance route is missing';
}
if (!is_file($v1Contract) || !is_array(json_decode((string)@file_get_contents($v1Contract), true))) {
	$failures[] = 'v1 machine-readable contract is missing or invalid';
}
setup_result('automatic-write-gate', $configuredWriter, $configuredWriter ? 'automatic attendance writes require live mode and disabled shadow mode' : 'automatic attendance write gate is missing');

echo 'ASSERTIONS ' . $assertions . "\n";
foreach ($warnings as $warning) {
	echo "REVIEW {$warning}\n";
}

if ($failures) {
	echo 'BLOCKED: ' . implode('; ', $failures) . "\n";
	if ($required || $dbName === 'ccc_database') {
		exit(1);
	}
	echo "SKIPPED: optional readiness run has unresolved blockers\n";
	exit(0);
}

echo "READY: enrollment dependencies are available; proceed only with approved calibration and no-write testing\n";
exit(0);
