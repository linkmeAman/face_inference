<?php

if (!interface_exists('FaceEnrollmentInferenceClient')) {
	require_once dirname(__DIR__) . '/services/FaceEnrollmentService.php';
}
if (!interface_exists('FaceRecognitionProvider')) {
	require_once dirname(__DIR__) . '/contracts/FaceRecognitionProvider.php';
}

class FaceInferenceException extends RuntimeException
{
	public $reason;
	public function __construct(string $message = '', int $code = 0, string $reason = 'INFERENCE_FAILED')
	{
		$this->reason = $reason;
		parent::__construct($message, $code);
	}
}
class FaceInferenceUnavailableException extends FaceInferenceException {}
class FaceInferenceOverloadedException extends FaceInferenceException {}
class FaceInferenceTimeoutException extends FaceInferenceException {}
class FaceInferenceAuthException extends FaceInferenceException {}

/**
 * Private HTTP client for enrollment encoding.
 *
 * The URL and token are configuration, never hardcoded. Request bodies are
 * not logged and are sent only to the configured private inference service.
 */
class FaceInferenceClient implements FaceEnrollmentInferenceClient, FaceRecognitionProvider
{
	private $baseUrl;
	private $token;
	private $timeout;
	private $maxFrameBytes;

	public function __construct(?string $baseUrl = null, ?string $token = null, int $timeout = 15, int $maxFrameBytes = 1000000)
	{
		$defaultBaseUrl = class_exists('FaceAttendanceConfig') ? FaceAttendanceConfig::getEnv('FACE_INFERENCE_URL') : (getenv('FACE_INFERENCE_URL') ?: ($_SERVER['FACE_INFERENCE_URL'] ?? ''));
		$defaultToken = class_exists('FaceAttendanceConfig') ? FaceAttendanceConfig::getEnv('FACE_INFERENCE_SHARED_SECRET') : (getenv('FACE_INFERENCE_SHARED_SECRET') ?: ($_SERVER['FACE_INFERENCE_SHARED_SECRET'] ?? ''));
		$this->baseUrl = rtrim($baseUrl !== null ? $baseUrl : $defaultBaseUrl, '/');
		$this->token = $token !== null ? $token : $defaultToken;
		$this->timeout = $timeout;
		$this->maxFrameBytes = $maxFrameBytes;
		if ($this->baseUrl === '') {
			throw new InvalidArgumentException('FACE_INFERENCE_URL is required');
		}
	}

	public function checkReadiness(): bool
	{
		return $this->readinessStatus()['ready'];
	}

	/** Optional capability; the stable provider interface remains unchanged. */
	public function readinessStatus(): array
	{
		$terminal = ['ready' => false, 'retryable' => false, 'reason' => 'SYSTEM_NOT_READY'];
		if (!function_exists('curl_init')) return $terminal;
		$curl = curl_init($this->baseUrl . '/readyz');
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 2]);
		$response = curl_exec($curl);
		$errno = curl_errno($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if ($errno === CURLE_OPERATION_TIMEDOUT) return ['ready' => false, 'retryable' => true, 'reason' => 'INFERENCE_TIMEOUT'];
		if ($errno !== 0 || $status === 0) return ['ready' => false, 'retryable' => true, 'reason' => 'INFERENCE_OFFLINE'];
		$body = is_string($response) ? json_decode($response, true) : null;
		if (!is_array($body)) return $terminal;
		if ($status === 200 && ($body['status'] ?? '') === 'ready') return ['ready' => true, 'retryable' => false, 'reason' => 'READY'];
		$reason = $body['reason_code'] ?? ($body['reason'] ?? ($body['error'] ?? ''));
		if (in_array($status, [429, 503], true) && $reason === 'CONCURRENCY_LIMIT_EXCEEDED') {
			return ['ready' => false, 'retryable' => true, 'reason' => 'INFERENCE_BUSY'];
		}
		return $terminal;
	}

	public function runtimeHealth(): array
	{
		if (!function_exists('curl_init')) return [];
		$curl = curl_init($this->baseUrl . '/health');
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 2]);
		$body = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		$decoded = is_string($body) ? json_decode($body, true) : null;
		return $status === 200 && is_array($decoded) ? $decoded : [];
	}

	public function providerMetadata(): array
	{
		$runtime = $this->runtimeProviderMetadata();
		if ($runtime !== null) {
			return $runtime;
		}
		return $this->configuredProviderMetadata();
	}

	/**
	 * The duplicate policy is deliberately read from the live inference
	 * service.  Unlike provider metadata, it has no environment fallback:
	 * setup must not block a child with a stale or locally invented threshold.
	 */
	public function identityPolicy(): ?array
	{
		$health = $this->runtimeHealth();
		$policy = $health['identity_policy'] ?? null;
		if (!is_array($policy)
			|| ($health['status'] ?? '') !== 'ok'
			|| empty($health['model_integrity_verified'])
			|| empty($health['calibration_approved'])
			|| empty($policy['calibration_approved'])) {
			return null;
		}
		$version = (string)($policy['policy_version'] ?? '');
		$review = $policy['review_threshold'] ?? null;
		$block = $policy['duplicate_block_threshold'] ?? null;
		$support = $policy['min_template_support'] ?? null;
		$margin = $policy['min_margin'] ?? null;
		if ($version === '' || !is_numeric($review) || !is_numeric($block) || !is_numeric($support) || !is_numeric($margin)) {
			return null;
		}
		$review = (float)$review;
		$block = (float)$block;
		$support = (int)$support;
		$margin = (float)$margin;
		if ($review < 0.0 || $block < $review || $block > 1.0 || $support < 2 || $margin < 0.0 || $margin > 1.0) {
			return null;
		}
		return [
			'policy_version' => $this->safeMetadataValue($version),
			'review_threshold' => $review,
			'duplicate_block_threshold' => $block,
			'min_template_support' => $support,
			'min_margin' => $margin,
			'model_version_id' => (int)($health['model_version_id'] ?? 0),
			'model_version' => $this->safeMetadataValue((string)($health['model_version'] ?? '')),
			'recognition_generation' => preg_match('/^[a-f0-9]{64}$/', (string)($health['recognition_generation'] ?? ''))
				? (string)$health['recognition_generation'] : '',
		];
	}

	private function configuredProviderMetadata(): array
	{
		$modelVersionId = (int)$this->faceEnv("FACE_MODEL_VERSION_ID", "0");
		if ($modelVersionId < 1) {
			throw new FaceInferenceException("FACE_MODEL_VERSION_ID must identify the active template model");
		}
		return [
			"provider_id" => $this->safeMetadataValue($this->faceEnv("FACE_RECOGNITION_PROVIDER_ID", "local-onnx")),
			"provider_version" => $this->safeMetadataValue($this->faceEnv("FACE_RECOGNITION_PROVIDER_VERSION", "1")),
			"model_version_id" => $modelVersionId,
			"model_version" => $this->safeMetadataValue($this->faceEnv("FACE_MODEL_VERSION", "unconfigured")),
			"calibration_policy_version" => $this->safeMetadataValue($this->faceEnv("FACE_CALIBRATION_POLICY_VERSION", "unconfigured")),
			"recognition_generation" => "",
		];
	}

	private function faceEnv(string $key, string $fallback = ''): string
	{
		if (class_exists('FaceAttendanceConfig')) {
			$value = FaceAttendanceConfig::getEnv($key);
			if ($value !== '') return $value;
		}
		$value = getenv($key);
		return $value === false || $value === '' ? $fallback : (string)$value;
	}

	private function runtimeProviderMetadata(): ?array
	{
		if (!function_exists("curl_init")) {
			return null;
		}
		$curl = curl_init($this->baseUrl . "/health");
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 1,
			CURLOPT_TIMEOUT => 2,
		]);
		$response = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if ($status !== 200 || !is_string($response)) {
			return null;
		}
		$decoded = json_decode($response, true);
		if (!is_array($decoded) || ($decoded["status"] ?? "") !== "ok") {
			return null;
		}
		foreach (["provider_id", "provider_version", "model_version", "calibration_policy_version"] as $field) {
			if (!isset($decoded[$field]) || (string)$decoded[$field] === "") {
				return null;
			}
		}
		$modelVersionId = (int)($decoded["model_version_id"] ?? 0);
		if ($modelVersionId < 1) {
			return null;
		}
		return [
			"provider_id" => $this->safeMetadataValue((string)$decoded["provider_id"]),
			"provider_version" => $this->safeMetadataValue((string)$decoded["provider_version"]),
			"model_version_id" => $modelVersionId,
			"model_version" => $this->safeMetadataValue((string)$decoded["model_version"]),
			"calibration_policy_version" => $this->safeMetadataValue((string)$decoded["calibration_policy_version"]),
			"recognition_generation" => preg_match('/^[a-f0-9]{64}$/', (string)($decoded['recognition_generation'] ?? '')) ? (string)$decoded['recognition_generation'] : '',
		];
	}

	private function enrollmentPost(string $path, array $body): array
	{
		$payload = json_encode($body, JSON_UNESCAPED_SLASHES);
		if (!is_string($payload)) {
			throw new FaceEnrollmentException('INVALID_REQUEST', 'Inference request could not be serialized');
		}
		if (!function_exists('curl_init')) {
			throw new FaceEnrollmentException('INFERENCE_UNAVAILABLE', 'PHP cURL is unavailable');
		}

		$curl = curl_init($this->baseUrl . $path);
		curl_setopt_array($curl, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_HTTPHEADER => array_values(array_filter([
				'Content-Type: application/json',
				$this->token !== '' ? 'X-Face-Inference-Token: ' . $this->token : null,
			])),
		]);
		$response = curl_exec($curl);
		$errno = curl_errno($curl);
		$errorMsg = curl_error($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($errno === 28) {
			throw new FaceInferenceTimeoutException('Enrollment inference timed out: ' . $errorMsg, 408);
		}
		if ($errno !== 0 || $status === 0) {
			throw new FaceInferenceUnavailableException('Private inference unreachable: ' . ($errorMsg ?: "HTTP {$status}"), 503);
		}
		if ($status === 503 || $status === 429) {
			throw new FaceInferenceOverloadedException('Private inference service overloaded', $status);
		}
		if (!is_string($response) || $status < 200 || $status >= 300) {
			$decodedErr = is_string($response) ? json_decode($response, true) : null;
			$reason = is_array($decodedErr) && !empty($decodedErr['reason']) ? (string)$decodedErr['reason'] : 'INFERENCE_FAILED';
			$msg = is_array($decodedErr) && !empty($decodedErr['message']) ? (string)$decodedErr['message'] : 'Private inference service did not complete request';
			throw new FaceEnrollmentException($reason, $msg);
		}
		$decoded = json_decode($response, true);
		if (!is_array($decoded) || isset($decoded['error'])) {
			throw new FaceEnrollmentException('INFERENCE_FAILED', 'Private inference response was invalid');
		}
		$provider = $this->providerMetadata();
		$runtimePolicy = (string)($decoded["calibration_policy_version"] ?? ($decoded["policy_version"] ?? ""));
		$decoded["provider_id"] = $this->safeMetadataValue((string)($decoded["provider_id"] ?? $provider["provider_id"]));
		$decoded["provider_version"] = $this->safeMetadataValue((string)($decoded["provider_version"] ?? $provider["provider_version"]));
		$decoded["model_version_id"] = (int)($decoded["model_version_id"] ?? 0);
		if ($decoded["model_version_id"] < 1) $decoded["model_version_id"] = $provider["model_version_id"];
		$decoded["model_version"] = $this->safeMetadataValue((string)($decoded["model_version"] ?? $provider["model_version"]));
		$decoded["calibration_policy_version"] = $this->safeMetadataValue($runtimePolicy !== "" ? $runtimePolicy : $provider["calibration_policy_version"]);
		return $decoded;
	}

	public function encodeEnrollment(array $frames, array $captureContexts = []): array
	{
		if (count($frames) < 7 || count($frames) > 10) {
			throw new FaceEnrollmentException('FRAME_COUNT', 'Enrollment requires 7 to 10 frames');
		}
		if ($captureContexts && count($captureContexts) !== count($frames)) {
			throw new FaceEnrollmentException('INVALID_CAPTURE_CONTEXT', 'Capture contexts must match enrollment frames');
		}
		$encoded = [];
		foreach ($frames as $frame) {
			if (!is_string($frame) || $frame === '' || strlen($frame) > $this->maxFrameBytes) {
				throw new FaceEnrollmentException('INVALID_FRAME', 'Enrollment frame is invalid or too large');
			}
			$encoded[] = base64_encode($frame);
		}

		$payload = ['frames' => $encoded];
		if ($captureContexts) $payload['capture_contexts'] = array_values($captureContexts);
		return $this->enrollmentPost('/internal/enrollments/encode', $payload);
	}

	public function encodeReference($frameOrFrames, $captureContexts = null): array
	{
		if (is_array($frameOrFrames)) {
			if (empty($frameOrFrames)) {
				throw new FaceEnrollmentException('INVALID_FRAME', 'Reference portrait burst is empty');
			}
			if (is_array($captureContexts) && $captureContexts && count($captureContexts) !== count($frameOrFrames)) {
				throw new FaceEnrollmentException('INVALID_CAPTURE_CONTEXT', 'Capture contexts must match reference frames');
			}
			$encoded = [];
			foreach ($frameOrFrames as $f) {
				if (!is_string($f) || $f === '' || strlen($f) > $this->maxFrameBytes) {
					throw new FaceEnrollmentException('INVALID_FRAME', 'Reference portrait frame is invalid or too large');
				}
				$encoded[] = base64_encode($f);
			}
			$payload = ['frames' => $encoded];
			if (is_array($captureContexts) && $captureContexts) $payload['capture_contexts'] = array_values($captureContexts);
			return $this->enrollmentPost('/internal/reference/encode', $payload);
		}
		if (!is_string($frameOrFrames) || $frameOrFrames === '' || strlen($frameOrFrames) > $this->maxFrameBytes) {
			throw new FaceEnrollmentException('INVALID_FRAME', 'Reference portrait frame is invalid or too large');
		}
		$payload = ['image' => base64_encode($frameOrFrames)];
		if (is_array($captureContexts) && $captureContexts) $payload['capture_context'] = $captureContexts;
		return $this->enrollmentPost('/internal/reference/encode', $payload);
	}

	public function encodeEnrollmentV2(array $burstA, array $burstB, array $referenceEmbedding, array $captureContextsA = [], array $captureContextsB = [], ?string $poseProtocol = null): array
	{
		$expectedA = $poseProtocol === FaceEnrollmentService::GUIDED_SELECTION_PROTOCOL ? 10 : 5;
		if (count($burstA) !== $expectedA) {
			throw new FaceEnrollmentException('BURST_A_FRAME_COUNT', "Burst A requires exactly {$expectedA} frames");
		}
		if (count($burstB) !== 3) {
			throw new FaceEnrollmentException('BURST_B_FRAME_COUNT', 'Burst B requires exactly 3 frames');
		}
		if (empty($referenceEmbedding) || !is_array($referenceEmbedding)) {
			throw new FaceEnrollmentException('REFERENCE_REQUIRED', 'Active reference embedding is required');
		}
		if (($captureContextsA && count($captureContextsA) !== count($burstA))
			|| ($captureContextsB && count($captureContextsB) !== count($burstB))) {
			throw new FaceEnrollmentException('INVALID_CAPTURE_CONTEXT', 'Capture contexts must match both enrollment bursts');
		}
		$encodedA = [];
		foreach ($burstA as $frame) {
			if (!is_string($frame) || $frame === '' || strlen($frame) > $this->maxFrameBytes) {
				throw new FaceEnrollmentException('INVALID_FRAME', 'Burst A frame is invalid or too large');
			}
			$encodedA[] = base64_encode($frame);
		}
		$encodedB = [];
		foreach ($burstB as $frame) {
			if (!is_string($frame) || $frame === '' || strlen($frame) > $this->maxFrameBytes) {
				throw new FaceEnrollmentException('INVALID_FRAME', 'Burst B frame is invalid or too large');
			}
			$encodedB[] = base64_encode($frame);
		}
		$payload = [
			'burst_a' => $encodedA,
			'burst_b' => $encodedB,
			'reference_embedding' => array_values(array_map('floatval', $referenceEmbedding)),
		];
		if ($poseProtocol !== null) {
			if (!$this->supportsEnrollmentProtocol($poseProtocol)) {
				throw new FaceEnrollmentException('UNSUPPORTED_POSE_PROTOCOL', 'Unknown guided enrollment protocol');
			}
			$payload['pose_protocol'] = $poseProtocol;
		}
		if ($captureContextsA) $payload['capture_contexts_a'] = array_values($captureContextsA);
		if ($captureContextsB) $payload['capture_contexts_b'] = array_values($captureContextsB);
		return $this->enrollmentPost('/internal/enrollments/encode-v2', $payload);
	}

	public function supportsEnrollmentProtocol(string $protocol): bool
	{
		return in_array($protocol, [FaceEnrollmentService::GUIDED_POSE_PROTOCOL,
			FaceEnrollmentService::GUIDED_SELECTION_PROTOCOL,
			FaceEnrollmentService::GUIDED_ADAPTIVE_PROTOCOL], true);
	}

	public function previewEnrollment(string $frame, ?array $captureContext = null): array
	{
		if ($frame === '' || strlen($frame) > $this->maxFrameBytes) {
			throw new FaceEnrollmentException('INVALID_FRAME', 'Enrollment preview frame is invalid or too large');
		}
		return $this->previewFrame($frame, $captureContext);
	}

	public function previewAttendance(string $frame, ?array $captureContext = null): array
	{
		if ($frame === '' || strlen($frame) > $this->maxFrameBytes) {
			throw new RuntimeException('Attendance preview frame is invalid or too large');
		}
		return $this->previewFrame($frame, $captureContext);
	}

	public function inferEvidence(string $frame, array $candidates, ?array $captureContext = null): array
	{
		if ($frame === '' || strlen($frame) > $this->maxFrameBytes) {
			throw new RuntimeException('Preview frame is invalid or too large');
		}
		$payload = [
			'image' => base64_encode($frame),
			'candidates' => array_values($candidates),
		];
		if ($captureContext) $payload['capture_context'] = $captureContext;
		return $this->requestJson('/v1/infer', $payload);
	}

	private function previewFrame(string $frame, ?array $captureContext = null): array
	{
		$payload = [
			'frames' => [base64_encode($frame)],
		];
		if ($captureContext) $payload['capture_context'] = $captureContext;
		return $this->requestJson('/internal/enrollments/preview', $payload);
	}

	public function initializeScan(string $scanId, array $candidates, int $ttl): array
	{
		return $this->requestJson('/internal/scans/' . rawurlencode($scanId) . '/roster', [
			'candidates' => array_values($candidates),
			'ttl_seconds' => $ttl,
		]);
	}

	public function recognizeAttempt(string $scanId, array $frames, string $challengeToken = '', array $captureContexts = [], ?float $clientZVariance = null): array
	{
		if (count($frames) < 1 || count($frames) > 5) {
			throw new RuntimeException('Recognition attempts require one to five frames');
		}
		$encoded = [];
		foreach ($frames as $frame) {
			if (!is_string($frame) || $frame === '' || strlen($frame) > $this->maxFrameBytes) {
				throw new RuntimeException('Recognition frame is invalid or too large');
			}
			$encoded[] = base64_encode($frame);
		}
		$payload = [
			'frames' => $encoded,
			'client_z_variance' => $clientZVariance,
			'FACE_ATTENDANCE_SHADOW_MODE' => $this->faceEnv('FACE_ATTENDANCE_SHADOW_MODE', '0'),
		];
		if ($challengeToken !== '') {
			if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $challengeToken)) {
				throw new RuntimeException('Challenge token is invalid');
			}
			$payload['challenge_token'] = $challengeToken;
		}
		if ($captureContexts) {
			if (count($captureContexts) !== count($frames)) {
				throw new RuntimeException('Capture contexts must match recognition frames');
			}
			$payload['capture_contexts'] = array_values($captureContexts);
		}
		return $this->requestJson('/internal/scans/' . rawurlencode($scanId) . '/attempts', $payload);
	}

	public function deleteScan(string $scanId): void
	{
		$this->requestJson('/internal/scans/' . rawurlencode($scanId), [], 'DELETE');
	}

	private function requestJson(string $path, array $body, string $method = 'POST'): array
	{
		if (!function_exists('curl_init')) {
			throw new RuntimeException('PHP cURL is unavailable');
		}
		$payload = json_encode($body, JSON_UNESCAPED_SLASHES);
		if (!is_string($payload)) {
			throw new RuntimeException('Inference request could not be serialized');
		}
		$curl = curl_init($this->baseUrl . $path);
		$options = [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_HTTPHEADER => array_values(array_filter([
				'Content-Type: application/json',
				'Connection: close',
				$this->token !== '' ? 'X-Face-Inference-Token: ' . $this->token : null,
			])),
		];
		curl_setopt_array($curl, $options);
		$response = curl_exec($curl);
		$errno = curl_errno($curl);
		$errorMsg = curl_error($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($errno === 28) {
			throw new FaceInferenceTimeoutException('Private inference request timed out: ' . $errorMsg, 408);
		}
		if ($errno !== 0 || $status === 0) {
			throw new FaceInferenceUnavailableException('Private inference unreachable: ' . ($errorMsg ?: "HTTP {$status}"), 503);
		}
		$failure = is_string($response) ? json_decode($response, true) : null;
		$failureReason = is_array($failure) ? ($failure['reason_code'] ?? ($failure['reason'] ?? ($failure['error'] ?? ''))) : '';
		if (in_array($status, [429, 503], true) && $failureReason === 'CONCURRENCY_LIMIT_EXCEEDED') {
			throw new FaceInferenceOverloadedException('Private inference service overloaded (HTTP ' . $status . ')', $status);
		}
		if ($status === 401 || $status === 403) {
			throw new FaceInferenceAuthException('Private inference authentication failed (HTTP ' . $status . ')', $status);
		}
		if (!is_string($response) || $status < 200 || $status >= 300) {
			$decodedErr = is_string($response) ? json_decode($response, true) : null;
			$reason = is_array($decodedErr) ? (string)($decodedErr['reason_code'] ?? ($decodedErr['reason'] ?? ($decodedErr['error'] ?? 'INFERENCE_FAILED'))) : 'INFERENCE_FAILED';
			$message = is_array($decodedErr) ? (string)($decodedErr['message'] ?? 'Private inference request failed') : 'Private inference service request failed with HTTP ' . $status;
			throw new FaceInferenceException($message, $status, $reason);
		}
		$decoded = json_decode($response, true);
		if (!is_array($decoded) || isset($decoded['error'])) {
			$reason = is_array($decoded) ? ($decoded['error'] ?? 'INVALID_RESPONSE') : 'INVALID_RESPONSE';
			throw new FaceInferenceException('Private inference response was invalid: ' . $reason, 0, (string)$reason);
		}
		return $decoded;
	}

	private function safeMetadataValue(string $value): string
	{
		$value = trim($value);
		if ($value === '' || strlen($value) > 100 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
			throw new FaceInferenceException('Recognition provider metadata is invalid');
		}
		return $value;
	}
}
