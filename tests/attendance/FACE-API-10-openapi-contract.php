<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/docs/api/face-attendance-v1.openapi.json';
$failures = [];

function openApiCheck(bool $condition, string $message): void
{
	global $failures;
	if (!$condition) {
		$failures[] = $message;
	}
}

$document = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
openApiCheck(is_array($document), 'OpenAPI document is missing or invalid JSON');
openApiCheck(($document['openapi'] ?? '') === '3.1.0', 'OpenAPI version must be 3.1.0');
openApiCheck(($document['info']['version'] ?? '') === '1.0.0', 'API semantic version is missing');

$post = $document['paths']['/routes/v1/face-attendance.php']['post'] ?? [];
$parameters = $post['parameters'] ?? [];
$operations = [];
foreach ($parameters as $parameter) {
	if (($parameter['name'] ?? '') === 'operation' && ($parameter['in'] ?? '') === 'query') {
		$operations = $parameter['schema']['enum'] ?? [];
	}
}
foreach (['start', 'attempt', 'status', 'complete', 'cancel', 'enroll', 'revoke'] as $operation) {
	openApiCheck(in_array($operation, $operations, true), "OpenAPI operation {$operation} is missing");
}
foreach (['uidAuth', 'bidAuth', 'tokenAuth', 'deviceAuth'] as $scheme) {
	openApiCheck(isset($document['components']['securitySchemes'][$scheme]), "security scheme {$scheme} is missing");
}

$response = $document['components']['schemas']['FaceAttendanceResponse']['properties'] ?? [];
openApiCheck(($response['api_version']['const'] ?? '') === 'v1', 'response schema does not freeze api_version=v1');
openApiCheck(($response['pad_status']['const'] ?? '') === 'DEFERRED', 'response schema does not freeze PAD as deferred');
openApiCheck(($response['commit_mode']['enum'] ?? []) === ['TEACHER_REVIEW', 'AUTO_PRESENT'], 'response schema does not expose configured commit modes');
openApiCheck(($response['live_write']['type'] ?? '') === 'boolean', 'response schema does not expose live-write state');
openApiCheck(($response['effect']['enum'] ?? []) === ['SELECT_PRESENT', 'ATTENDANCE_RECORDED', 'ALREADY_PRESENT', 'NONE'], 'response schema does not expose automatic attendance effects');

$multipart = $document['paths']['/routes/v1/face-attendance.php']['post']['requestBody']['content']['multipart/form-data']['schema']['oneOf'] ?? [];
$multipartRefs = array_map(static function ($schema): string {
	return is_array($schema) ? (string)($schema['$ref'] ?? '') : '';
}, $multipart);
openApiCheck(in_array('#/components/schemas/AttemptMultipartRequest', $multipartRefs, true), 'multipart attempt schema is missing');
openApiCheck(in_array('#/components/schemas/EnrollMultipartRequest', $multipartRefs, true), 'multipart enrollment schema is missing');
$enrollSchema = $document['components']['schemas']['EnrollMultipartRequest'] ?? [];
openApiCheck(($enrollSchema['properties']['frames']['minItems'] ?? 0) === 7, 'multipart enrollment must require seven frames');
openApiCheck(($enrollSchema['properties']['teacher_confirmed_member']['const'] ?? null) === true, 'multipart enrollment must require teacher confirmation');

if ($failures) {
	fwrite(STDERR, "FAIL FACE-API-10 OpenAPI contract\n - " . implode("\n - ", $failures) . "\n");
	exit(1);
}

echo "PASS FACE-API-10 OpenAPI contract\n";
