<?php

declare(strict_types=1);

/**
 * Additively provisions the persistent face schema in the two approved test
 * databases. Existing/partial deployments are never dropped or overwritten.
 */

$root = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);
$dbName = getenv('ATTENDANCE_TEST_DB') ?: 'ccc_database_test';
$centralDbName = getenv('ATTENDANCE_TEST_CENTRAL_DB') ?: 'ccc_central_test';
$dbHost = getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('ATTENDANCE_TEST_DB_USER') ?: 'root';
$dbPassword = getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '';

if ($dbName !== 'ccc_database_test' || $centralDbName !== 'ccc_central_test') {
	fwrite(STDERR, "Refusing to provision databases outside ccc_database_test and ccc_central_test.\n");
	exit(2);
}

// Validate every non-database prerequisite before issuing any DDL. MySQL DDL
// commits implicitly, so discovering a missing model setting after CREATE or
// ALTER would leave a misleading half-ready persistent test deployment.
$baseMigration = $root . '/database/migrations/20260911_02_face_attendance_tables.sql';
$providerMigration = $root . '/database/migrations/20260921_01_supervised_face_scan_contract.sql';
$manifestPath = $root . '/services/face-inference/models/MODEL-MANIFEST.json';
foreach ([$baseMigration, $providerMigration, $manifestPath] as $requiredFile) {
	if (!is_file($requiredFile) || !is_readable($requiredFile)) {
		fwrite(STDERR, "Required setup artifact is unavailable.\n");
		exit(2);
	}
}

$manifest = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
	fwrite(STDERR, "Model manifest is unavailable.\n");
	exit(2);
}
$modelVersionId = (int)(getenv('FACE_MODEL_VERSION_ID') ?: '0');
$providerId = trim((string)(getenv('FACE_RECOGNITION_PROVIDER_ID') ?: 'local-onnx'));
$providerVersion = trim((string)(getenv('FACE_RECOGNITION_PROVIDER_VERSION') ?: '1'));
$policyVersion = trim((string)(getenv('FACE_CALIBRATION_POLICY_VERSION') ?: ''));
$bundleVersion = trim((string)($manifest['bundle_version'] ?? ''));
foreach ([$providerId, $providerVersion, $policyVersion, $bundleVersion] as $value) {
	if ($value === '' || strlen($value) > 100 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
		fwrite(STDERR, "Configured provider/model metadata is invalid.\n");
		exit(2);
	}
}
if ($modelVersionId < 1) {
	fwrite(STDERR, "FACE_MODEL_VERSION_ID must be positive.\n");
	exit(2);
}
$detectorChecksum = (string)($manifest['detector']['sha256'] ?? '');
$recognizerChecksum = (string)($manifest['recognizer']['sha256'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $detectorChecksum) || !preg_match('/^[a-f0-9]{64}$/', $recognizerChecksum)) {
	fwrite(STDERR, "Model manifest checksums are invalid.\n");
	exit(2);
}
$bundleChecksum = hash('sha256', $detectorChecksum . ':' . $recognizerChecksum . ':sface-alignment-v1');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
	$conn = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
	$central = new mysqli($dbHost, $dbUser, $dbPassword, $centralDbName);
} catch (Throwable $directException) {
	require_once $root . '/classes/dbconnection.class.php';
	try {
		$connector = new dbconnection();
		$conn = $connector->connect($dbName);
		$central = $connector->connect($centralDbName);
	} catch (Throwable $fallbackException) {
		fwrite(STDERR, "Unable to connect to the approved test databases.\n");
		exit(2);
	}
}
$conn->set_charset('utf8mb4');
$central->set_charset('utf8mb4');
$central->close();

$tables = [
	'biometric_consent',
	'face_template',
	'face_model_version',
	'attendance_scan',
	'attendance_scan_roster',
	'recognition_attempt',
	'attendance_event',
];
$present = [];
foreach ($tables as $table) {
	$row = $conn->query("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}'")->fetch_assoc();
	if ((int)$row['total'] === 1) {
		$present[] = $table;
	}
}
if ($present && count($present) !== count($tables)) {
	fwrite(STDERR, 'Refusing partial face schema; present tables: ' . implode(', ', $present) . "\n");
	exit(2);
}

if (!$present) {
	$requiredParents = ['attendance', 'batch', 'branch', 'member'];
	$missingParents = [];
	foreach ($requiredParents as $table) {
		$row = $conn->query("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}'")->fetch_assoc();
		if ((int)$row['total'] !== 1) {
			$missingParents[] = $table;
		}
	}
	if ($missingParents) {
		fwrite(STDERR, 'Refusing setup because required parent tables are missing: ' . implode(', ', $missingParents) . "\n");
		exit(2);
	}
}

$providerColumns = [
	'face_model_version' => ['provider_id', 'provider_version'],
	'attendance_scan' => ['provider_id', 'provider_version', 'model_version_id', 'calibration_policy_version'],
];
$presentProviderColumns = 0;
$totalProviderColumns = 0;
foreach ($providerColumns as $table => $columns) {
	foreach ($columns as $column) {
		$totalProviderColumns++;
		$row = $conn->query("SELECT COUNT(*) AS total FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}' AND column_name = '{$column}'")->fetch_assoc();
		$presentProviderColumns += (int)$row['total'];
	}
}
if ($presentProviderColumns > 0 && $presentProviderColumns !== $totalProviderColumns) {
	fwrite(STDERR, "Refusing partial supervised-provider migration.\n");
	exit(2);
}

// If the base registry already exists, prove that ALTER defaults and the
// configured tuple can converge before applying the provider migration.
if ($present && $presentProviderColumns === 0) {
	$existingLegacy = $conn->query("SELECT id, policy_version, embedding_dimension, status FROM face_model_version WHERE id = '{$modelVersionId}' LIMIT 1")->fetch_assoc();
	if ($existingLegacy && ($providerId !== 'local-onnx'
		|| $providerVersion !== '1'
		|| (string)$existingLegacy['policy_version'] !== $policyVersion
		|| (int)$existingLegacy['embedding_dimension'] !== 128
		|| (string)$existingLegacy['status'] !== 'ACTIVE')) {
		fwrite(STDERR, "Existing legacy model registry row cannot be migrated to the configured provider tuple.\n");
		exit(2);
	}
}

if ($presentProviderColumns === $totalProviderColumns) {
	$existing = $conn->query("SELECT id, provider_id, provider_version, policy_version, embedding_dimension, status FROM face_model_version WHERE id = '{$modelVersionId}' LIMIT 1")->fetch_assoc();
	if ($existing && ((string)$existing['provider_id'] !== $providerId
		|| (string)$existing['provider_version'] !== $providerVersion
		|| (string)$existing['policy_version'] !== $policyVersion
		|| (int)$existing['embedding_dimension'] !== 128
		|| (string)$existing['status'] !== 'ACTIVE')) {
		fwrite(STDERR, "Existing model registry row does not match configured provider metadata.\n");
		 exit(2);
	}
}

if ($present) {
	$checksumOwner = $conn->query("SELECT id FROM face_model_version WHERE model_checksum = '{$conn->real_escape_string($bundleChecksum)}' LIMIT 1")->fetch_assoc();
	if ($checksumOwner && (int)$checksumOwner['id'] !== $modelVersionId) {
		fwrite(STDERR, "Configured model bundle checksum belongs to a different registry row.\n");
		exit(2);
	}
}

if (!$apply) {
	echo 'DRY RUN: base_schema=' . ($present ? 'present' : 'missing')
		. ' provider_schema=' . ($presentProviderColumns === $totalProviderColumns ? 'present' : 'missing') . "\n";
	echo "DRY RUN: provider/model/calibration configuration is valid; no database writes performed.\n";
	echo "Re-run with --apply to add only missing test schema and the configured model registry row.\n";
	exit(0);
}

function applySqlFile(mysqli $conn, string $path): void
{
	$sql = (string)file_get_contents($path);
	foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
		$statement = trim($statement);
		if ($statement !== '') {
			$conn->query($statement);
		}
	}
}

if (!$present) {
	applySqlFile($conn, $baseMigration);
	echo "APPLIED base face schema to ccc_database_test\n";
}
if ($presentProviderColumns === 0) {
	applySqlFile($conn, $providerMigration);
	echo "APPLIED supervised provider schema to ccc_database_test\n";
}

$existing = $conn->query("SELECT id, provider_id, provider_version, policy_version, embedding_dimension, status FROM face_model_version WHERE id = '{$modelVersionId}' LIMIT 1")->fetch_assoc();
if ($existing) {
	if ((string)$existing['provider_id'] !== $providerId
		|| (string)$existing['provider_version'] !== $providerVersion
		|| (string)$existing['policy_version'] !== $policyVersion
		|| (int)$existing['embedding_dimension'] !== 128
		|| (string)$existing['status'] !== 'ACTIVE') {
		throw new RuntimeException('Existing model registry row does not match configured provider metadata');
	}
	echo "UNCHANGED active model registry row {$modelVersionId}\n";
} else {
	$values = array_map([$conn, 'real_escape_string'], [$bundleVersion, $providerId, $providerVersion, $bundleChecksum, $policyVersion]);
	$conn->query("INSERT INTO face_model_version (id, model_name, provider_id, provider_version, detector_version, recognizer_version, preprocessing_version, embedding_dimension, model_checksum, license_reference, policy_version, status, created_by) VALUES ('{$modelVersionId}', '{$values[0]}', '{$values[1]}', '{$values[2]}', 'yunet-2023mar-int8', 'sface-2021dec-int8bq', 'sface-alignment-v1', '128', '{$values[3]}', 'services/face-inference/models/LICENSES.md', '{$values[4]}', 'ACTIVE', '0')");
	echo "CREATED active model registry row {$modelVersionId}\n";
}

$conn->close();
echo "READY ccc_database_test persistent face schema\n";
