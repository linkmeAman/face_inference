<?php

declare(strict_types=1);

/**
 * FACE-API-08: PHP 8 Countable/array null-safety on empty embeddings.
 *
 * Verifies that averageEmbedding and centroid handle empty or invalid
 * template embedding arrays safely without throwing count() TypeErrors.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/classes/services/FaceAttendanceApiService.php';
require_once $root . '/classes/services/FaceEnrollmentService.php';

$failures = [];

// Test Reflection for averageEmbedding in FaceAttendanceApiService
$reflectionApi = new ReflectionClass('FaceAttendanceApiService');
$averageMethod = $reflectionApi->getMethod('averageEmbedding');
$averageMethod->setAccessible(true);

$mockDb = (new ReflectionClass('mysqli'))->newInstanceWithoutConstructor();
$mockConfig = new FaceAttendanceFeatureConfig(false, false, false, false, []);
$mockCipher = new FaceEmbeddingCipher(str_repeat('k', 32), 'test-key');
$mockRecognition = new class implements FaceRecognitionProvider {
	public function checkReadiness(): bool { return true; }
	public function providerMetadata(): array { return []; }
	public function initializeScan(string $scanId, array $candidates, int $ttl): array { return []; }
	public function recognizeAttempt(string $scanId, array $frames, string $challengeToken = '', array $captureContexts = []): array { return []; }
	public function deleteScan(string $scanId): void {}
};
$apiService = new FaceAttendanceApiService($mockDb, $mockRecognition, $mockCipher, $mockConfig);

try {
	$result = $averageMethod->invoke($apiService, []);
	if ($result !== []) {
		$failures[] = 'averageEmbedding([]) must return []';
	}
} catch (TypeError $e) {
	$failures[] = 'averageEmbedding([]) threw TypeError: ' . $e->getMessage();
} catch (Throwable $e) {
	$failures[] = 'averageEmbedding([]) threw unexpected exception: ' . $e->getMessage();
}

// Test Reflection for centroid in FaceEnrollmentService
$reflectionEnr = new ReflectionClass('FaceEnrollmentService');
$centroidMethod = $reflectionEnr->getMethod('centroid');
$centroidMethod->setAccessible(true);

$mockInference = new class implements FaceEnrollmentInferenceClient {
	public function encodeEnrollment(array $frames, array $captureContexts = []): array { return []; }
	public function previewEnrollment(string $frame): array { return []; }
};
$enrService = new FaceEnrollmentService($mockDb, $mockInference, $mockCipher);

try {
	$result = $centroidMethod->invoke($enrService, []);
	if ($result !== []) {
		$failures[] = 'centroid([]) must return []';
	}
} catch (TypeError $e) {
	$failures[] = 'centroid([]) threw TypeError: ' . $e->getMessage();
} catch (Throwable $e) {
	$failures[] = 'centroid([]) threw unexpected exception: ' . $e->getMessage();
}

if ($failures) {
	foreach ($failures as $failure) {
		fwrite(STDERR, "FAIL FACE-API-08 {$failure}\n");
	}
	exit(1);
}

fwrite(STDOUT, "PASS FACE-API-08 averageEmbedding and centroid handle empty embeddings safely on PHP 8\n");
exit(0);
