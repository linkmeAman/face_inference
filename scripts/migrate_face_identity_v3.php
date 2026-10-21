<?php

require_once __DIR__ . '/../classes/dbconnection.class.php';

$apply = in_array('--apply', $argv ?? [], true);
$databases = ['ccc_database', 'ccc_database_test'];
$preprocessing = 'sface-opencv-aligncrop-rgb-raw-v2';

foreach ($databases as $dbName) {
	try {
		$conn = (new dbconnection())->connect($dbName);
	} catch (Throwable $error) {
		fwrite(STDERR, "SKIP {$dbName}: connection unavailable ({$error->getMessage()})\n");
		continue;
	}
	if (!$conn || $conn->connect_errno) {
		fwrite(STDERR, "SKIP {$dbName}: connection unavailable\n");
		continue;
	}

	$legacy = $conn->query("SELECT * FROM face_model_version WHERE id = 1 LIMIT 1");
	$row = $legacy ? $legacy->fetch_assoc() : null;
	if (!$row) {
		fwrite(STDERR, "SKIP {$dbName}: legacy model row 1 not found\n");
		$conn->close();
		continue;
	}

	$templateCount = (int)$conn->query("SELECT COUNT(*) AS c FROM face_template WHERE model_version_id = 1 AND status = 'ACTIVE'")->fetch_assoc()['c'];
	$referenceTable = $conn->query("SHOW TABLES LIKE 'face_identity_reference'");
	$referenceCount = 0;
	if ($referenceTable && $referenceTable->num_rows > 0) {
		$referenceCount = (int)$conn->query("SELECT COUNT(*) AS c FROM face_identity_reference WHERE model_version_id = 1 AND status = 'ACTIVE'")->fetch_assoc()['c'];
	}
	printf("%s %s: retire model=1, revoke templates=%d, supersede references=%d, register preprocessing=%s\n",
		$apply ? 'APPLY' : 'DRY-RUN', $dbName, $templateCount, $referenceCount, $preprocessing);
	if (!$apply) {
		$conn->close();
		continue;
	}

	$oldIndex = $conn->query("SHOW INDEX FROM face_model_version WHERE Key_name = 'uq_face_model_checksum'");
	$newIndex = $conn->query("SHOW INDEX FROM face_model_version WHERE Key_name = 'uq_face_model_checksum_preprocessing'");
	$hasOldIndex = $oldIndex && $oldIndex->num_rows > 0;
	$hasNewIndex = $newIndex && $newIndex->num_rows > 0;
	if ($hasOldIndex && !$hasNewIndex) {
		if (!$conn->query("ALTER TABLE face_model_version DROP INDEX uq_face_model_checksum, ADD UNIQUE KEY uq_face_model_checksum_preprocessing (model_checksum, preprocessing_version)")) {
			throw new RuntimeException("{$dbName}: failed to replace model-version unique index: {$conn->error}");
		}
	} elseif ($hasOldIndex) {
		if (!$conn->query("ALTER TABLE face_model_version DROP INDEX uq_face_model_checksum")) {
			throw new RuntimeException("{$dbName}: failed to drop legacy unique index: {$conn->error}");
		}
	} elseif (!$hasNewIndex) {
		if (!$conn->query("ALTER TABLE face_model_version ADD UNIQUE KEY uq_face_model_checksum_preprocessing (model_checksum, preprocessing_version)")) {
			throw new RuntimeException("{$dbName}: failed to add composite unique index: {$conn->error}");
		}
	}

	$conn->begin_transaction();
	try {
		$escapedPreprocessing = $conn->real_escape_string($preprocessing);
		$existing = $conn->query("SELECT id FROM face_model_version WHERE model_checksum = '{$row['model_checksum']}' AND preprocessing_version = '{$escapedPreprocessing}' LIMIT 1");
		if (!$existing || $existing->num_rows === 0) {
			$sql = "INSERT INTO face_model_version (model_name, provider_id, provider_version, detector_version, recognizer_version, preprocessing_version, embedding_dimension, model_checksum, license_reference, policy_version, status, created_by) VALUES (" .
				"'" . $conn->real_escape_string($row['model_name'] . '-opencv-preprocess-v2') . "', " .
				"'" . $conn->real_escape_string($row['provider_id']) . "', '2', " .
				"'" . $conn->real_escape_string($row['detector_version']) . "', " .
				"'" . $conn->real_escape_string($row['recognizer_version']) . "', '{$escapedPreprocessing}', " .
				(int)$row['embedding_dimension'] . ", '" . $conn->real_escape_string($row['model_checksum']) . "', " .
				"'" . $conn->real_escape_string($row['license_reference']) . "', 'face-policy-v3', 'PENDING_CALIBRATION', 0)";
			if (!$conn->query($sql)) {
				throw new RuntimeException("{$dbName}: failed to register v3 model: {$conn->error}");
			}
		}
		if (!$conn->query("UPDATE face_template SET status = 'REVOKED', revoked_at = CURRENT_TIMESTAMP WHERE model_version_id = 1 AND status = 'ACTIVE'")) {
			throw new RuntimeException("{$dbName}: failed to revoke templates: {$conn->error}");
		}
		if ($referenceTable && $referenceTable->num_rows > 0
			&& !$conn->query("UPDATE face_identity_reference SET status = 'SUPERSEDED' WHERE model_version_id = 1 AND status = 'ACTIVE'")) {
			throw new RuntimeException("{$dbName}: failed to supersede references: {$conn->error}");
		}
		if (!$conn->query("UPDATE face_model_version SET status = 'RETIRED', retired_at = CURRENT_TIMESTAMP WHERE id = 1")) {
			throw new RuntimeException("{$dbName}: failed to retire legacy model: {$conn->error}");
		}
		$conn->commit();
		fwrite(STDOUT, "DONE {$dbName}\n");
	} catch (Throwable $error) {
		$conn->rollback();
		$conn->close();
		throw $error;
	}
	$conn->close();
}
