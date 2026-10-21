<?php

declare(strict_types=1);

$path = dirname(__DIR__, 2) . '/tools/setup_face_test_databases.php';
$source = is_file($path) ? (string)file_get_contents($path) : '';
$failures = [];

function setupContractCheck(bool $condition, string $message): void
{
	global $failures;
	if (!$condition) {
		$failures[] = $message;
	}
}

setupContractCheck($source !== '', 'test database setup tool is missing');
setupContractCheck(strpos($source, '$dbName !== \'ccc_database_test\'') !== false, 'application test database is not exact-name guarded');
setupContractCheck(strpos($source, '$centralDbName !== \'ccc_central_test\'') !== false, 'central test database is not exact-name guarded');
setupContractCheck(strpos($source, "in_array('--apply'") !== false, 'persistent changes do not require explicit --apply');
setupContractCheck(stripos($source, 'DROP TABLE') === false, 'setup tool can drop existing tables');
setupContractCheck(stripos($source, 'DELETE FROM') === false, 'setup tool can delete existing rows');
setupContractCheck(stripos($source, 'UPDATE face_model_version') === false, 'setup tool can overwrite model registry metadata');
setupContractCheck(strpos($source, 'Refusing partial face schema') !== false, 'partial existing schemas are not rejected');
setupContractCheck(strpos($source, '20260921_01_supervised_face_scan_contract.sql') !== false, 'provider migration is not applied');
setupContractCheck(strpos($source, 'required parent tables are missing') !== false, 'foreign-key parent tables are not preflighted');

$applyGuard = strpos($source, 'if (!$apply)');
$configurationValidation = strpos($source, 'if ($modelVersionId < 1)');
$firstMigrationApply = strpos($source, 'applySqlFile($conn, $baseMigration)');
setupContractCheck($applyGuard !== false && $configurationValidation !== false && $firstMigrationApply !== false, 'setup ordering markers are missing');
setupContractCheck($configurationValidation < $applyGuard, 'provider/model configuration is validated only after the dry-run boundary');
setupContractCheck($configurationValidation < $firstMigrationApply, 'provider/model configuration is validated only after database writes begin');
setupContractCheck(strpos($source, 'Model manifest checksums are invalid') < $firstMigrationApply, 'model artifact integrity is validated only after database writes begin');
setupContractCheck(strpos($source, 'Existing legacy model registry row cannot be migrated') < $firstMigrationApply, 'legacy registry compatibility is not checked before ALTER');
setupContractCheck(strpos($source, 'Configured model bundle checksum belongs to a different registry row') < $firstMigrationApply, 'model checksum collision is not checked before migration');

if ($failures) {
	fwrite(STDERR, "FAIL FACE-TEST-DB-02 setup contract\n - " . implode("\n - ", $failures) . "\n");
	exit(1);
}

echo "PASS FACE-TEST-DB-02 additive test database setup contract\n";
