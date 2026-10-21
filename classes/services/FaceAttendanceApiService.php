<?php

if (!class_exists('FaceEmbeddingCipher')) {
	require_once __DIR__ . '/FaceEnrollmentService.php';
}
if (!class_exists('FaceErrorLogger')) {
	require_once __DIR__ . '/FaceErrorLogger.php';
}
if (!interface_exists('FaceRecognitionProvider')) {
	require_once dirname(__DIR__) . '/contracts/FaceRecognitionProvider.php';
}
if (!class_exists('AttendanceDomainService')) {
	require_once __DIR__ . '/AttendanceDomainService.php';
}
if (!class_exists('FaceAttendanceConfig')) {
	require_once __DIR__ . '/FaceAttendanceConfig.php';
}
require_once __DIR__ . '/FaceSelectionGate.php';

/** Runtime gates for the face scan API. Attendance commit remains a teacher action. */
class FaceAttendanceFeatureConfig
{
	public $enabled;
	public $shadowMode;
	public $liveWrite;
	public $punchOutEnabled;
	public $allowedBranchIds;

	public function __construct(bool $enabled, bool $shadowMode, bool $liveWrite, bool $punchOutEnabled, array $allowedBranchIds = [])
	{
		$this->enabled = $enabled;
		$this->shadowMode = $shadowMode;
		$this->liveWrite = $liveWrite;
		$this->punchOutEnabled = $punchOutEnabled;
		$this->allowedBranchIds = array_values(array_filter(array_map('intval', $allowedBranchIds), function ($id) {
			return $id > 0;
		}));
	}

	public static function fromDatabase(?mysqli $conn = null, int $branchId = 0): self
	{
		require_once __DIR__ . '/FaceAttendanceConfig.php';
		$cfg = FaceAttendanceConfig::get($conn, $branchId);
		$branches = [];
		$raw = FaceAttendanceConfig::getEnv('FACE_ALLOWED_BRANCH_IDS');
		if (is_string($raw) && $raw !== '') {
			$branches = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
		}
		return new self(
			!empty($cfg['FACE_ATTENDANCE_ENABLED']),
			!empty($cfg['FACE_ATTENDANCE_SHADOW_MODE']),
			!empty($cfg['FACE_ATTENDANCE_LIVE_WRITE']),
			!empty($cfg['FACE_PUNCH_OUT_ENABLED']),
			$branches
		);
	}

	public static function fromEnvironment(): self
	{
		return self::fromDatabase();
	}

	public function branchAllowed(int $branchId): bool
	{
		return !$this->allowedBranchIds || in_array($branchId, $this->allowedBranchIds, true);
	}
}

/**
 * PHP-side lifecycle for one selected batch/date/session.
 *
 * The service snapshots the exact eligible roster and persists recognition
 * metadata. Shadow scans select a client row for the existing register; an
 * explicitly enabled live scan commits only a server-confirmed PRESENT event.
 */
class FaceAttendanceApiService
{
	private $conn;
	private $inference;
	private $cipher;
	private $config;
	private $scanTtl;
	private $maxFrameBytes;
	private $attendance;

	public function __construct(mysqli $conn, FaceRecognitionProvider $inference, FaceEmbeddingCipher $cipher, FaceAttendanceFeatureConfig $config, int $scanTtl = 300, int $maxFrameBytes = 1000000, $attendance = null)
	{
		$this->conn = $conn;
		$this->inference = $inference;
		$this->cipher = $cipher;
		$this->config = $config;
		$this->scanTtl = max(60, min($scanTtl, 900));
		$this->maxFrameBytes = max(1, min($maxFrameBytes, 1000000));
		$this->attendance = $attendance ?: new AttendanceDomainService($conn);
	}

	public function startFaceScan(array $request): array
	{
		try {
			$command = $this->normalizeStart($request);
			if (!$this->config->enabled) {
				return $this->rejected('FEATURE_DISABLED', 'Face attendance is disabled');
			}
			if (!FaceSelectionGate::enabled($this->conn)) {
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			if (!$this->config->branchAllowed($command['branch_id'])) {
				return $this->rejected('BRANCH_NOT_ALLOWED', 'Face attendance is not enabled for this branch');
			}
			$readiness = $this->readinessStatus();
			if (!$readiness['ready']) return $this->readinessRejection($readiness);
			if ($command['event_type'] === 'PUNCH_OUT' && !$this->config->punchOutEnabled) {
				return $this->rejected('PUNCH_OUT_DISABLED', 'Face punch-out is not enabled');
			}
			// The API owns this policy; a client cannot disable it by changing a
			// request flag. The existing manual flow remains governed by its own
			// server-side setting.
			if (substr($command['attendance_date'], 0, 7) !== date('Y-m')) {
				return $this->rejected('DATE_POLICY', 'Attendance date is outside the allowed month');
			}
			if (!$this->scheduledBatchIsValid($command)) {
				return $this->rejected('INVALID_SESSION', 'Batch, date, session, or selected time is not valid');
			}

			$provider = $this->providerMetadata();
			$generation = (string)($provider['recognition_generation'] ?? '');
			if (!preg_match('/^[a-f0-9]{64}$/', $generation)) {
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			$providerError = $this->validateProviderModel($provider);
			if ($providerError !== null) {
				return $this->rejected($providerError['reason'], $providerError['message']);
			}
			$members = $this->loadRoster($command, $provider['model_version_id']);
			$token = bin2hex(random_bytes(32));
			$browserBinding = (string)($request['browser_session_binding'] ?? '');
			if (FaceAttendanceConfig::getEnv('FACE_CAPTURE_NONCE_REQUIRED') === '1' && !preg_match('/^[a-f0-9]{64}$/', $browserBinding)) {
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			$captureNonce = bin2hex(random_bytes(24));
			$nonceHash = hash('sha256', $captureNonce);
			$expiresAt = date('Y-m-d H:i:s', time() + $this->scanTtl);
			$rosterVersion = hash('sha256', json_encode(array_map(function ($member) {
				return [(int)$member['id'], (string)$member['consent_status'], (int)$member['template_count']];
			}, $members), JSON_UNESCAPED_SLASHES));

			$candidates = [];
			$rosterRows = [];
			foreach ($members as $member) {
				// Every frozen roster row needs a unique opaque reference because the
				// database key is non-null and unique. Only mapped rows are sent to
				// inference; manual-only rows retain their reference for reconciliation.
				$candidateRef = bin2hex(random_bytes(16));
				if ($member['consent_status'] === 'PROGRAM_AUTHORIZED' && (int)$member['template_count'] > 0) {
					$embeddings = $this->loadEmbeddings((int)$member['id'], $provider['model_version_id']);
					if ($embeddings) {
						// Preserve independent references. Averaging templates can move a
						// member vector toward another identity and hides whether multiple
						// stored captures actually support the match.
						$candidates[] = ['candidate_ref' => $candidateRef, 'embeddings' => array_values($embeddings)];
					}
				}
				$rosterRows[] = [
					'member_id' => (int)$member['id'],
					'candidate_ref' => $candidateRef,
					'consent_status' => (string)$member['consent_status'],
					'template_count' => (int)$member['template_count'],
				];
			}

			if (!$candidates) {
				return $this->rejected('NO_MAPPED_CANDIDATES', 'No active face mappings are available in the selected roster');
			}

			try {
				// Prepare the private sidecar first. A transient outage must not create
				// a database scan that is immediately reported as CANCELLED while the
				// camera remains active and retries.
				$this->inference->initializeScan($token, $candidates, $this->scanTtl);
			} catch (FaceInferenceOverloadedException $exception) {
				FaceErrorLogger::record('FACE_SCAN_INFERENCE_OVERLOADED', [
					'scan_id' => 0,
					'reason' => 'CONCURRENCY_LIMIT_EXCEEDED',
				]);
				return $this->rejected('INFERENCE_BUSY', 'Inference service at capacity (maximum 4 concurrent requests), retrying automatically...');
			} catch (Throwable $exception) {
				FaceErrorLogger::record('FACE_SCAN_INFERENCE_UNAVAILABLE', [
					'scan_id' => 0,
					'reason' => 'INFERENCE_UNAVAILABLE',
				]);
				return $this->rejected('INFERENCE_UNAVAILABLE', 'Private face inference service is unavailable');
			}

			$this->conn->begin_transaction();
			try {
				$this->run("INSERT INTO attendance_scan (scan_token, branch_id, batch_id, attendance_date, session_id, event_type, actor_employee_id, roster_version, provider_id, provider_version, model_version_id, calibration_policy_version, status, expires_at, recognition_generation, browser_session_hash, capture_nonce_hash, capture_nonce_expires_at) VALUES ('{$this->escape($token)}', '{$command['branch_id']}', '{$command['batch_id']}', '{$this->escape($command['attendance_date'])}', '{$command['session_id']}', '{$command['event_type']}', '{$command['actor_employee_id']}', '{$rosterVersion}', '{$this->escape($provider['provider_id'])}', '{$this->escape($provider['provider_version'])}', '{$provider['model_version_id']}', '{$this->escape($provider['calibration_policy_version'])}', 'ACTIVE', '{$expiresAt}', '{$generation}', '{$this->escape($browserBinding)}', '{$nonceHash}', DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 SECOND))");
				$scanId = (int)$this->conn->insert_id;
				foreach ($rosterRows as $rosterRow) {
					$this->run("INSERT INTO attendance_scan_roster (scan_id, member_id, candidate_ref, eligibility_status, consent_status, template_count) VALUES ('{$scanId}', '{$rosterRow['member_id']}', '{$this->escape($rosterRow['candidate_ref'])}', 'ELIGIBLE', '{$this->escape($rosterRow['consent_status'])}', '{$rosterRow['template_count']}' )");
				}
				$this->conn->commit();
			} catch (Throwable $exception) {
				$this->conn->rollback();
				$this->deleteSidecar($token);
				return $this->rejected('SCAN_PERSIST_FAILED', 'Face scan could not be prepared');
			}

			if (!FaceSelectionGate::enabled($this->conn)) {
				$this->run("UPDATE attendance_scan SET status = 'CANCELLED', cancelled_at = CURRENT_TIMESTAMP WHERE id = '{$scanId}' AND status = 'ACTIVE'");
				$this->deleteSidecar($token);
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			return [
				'api_version' => 'v1',
				'state' => 'STARTED',
				'scan_id' => $scanId,
				'scan_token' => $token,
				'capture_nonce' => $captureNonce,
				'roster_version' => $rosterVersion,
				'expires_in' => $this->scanTtl,
				'roster_count' => count($members),
				'automatic_candidate_count' => count($candidates),
				'event_type' => $command['event_type'],
				'provider' => $provider,
				'pad_status' => 'DEFERRED',
			] + $this->responseMode();
		} catch (Throwable $exception) {
			FaceErrorLogger::record('FACE_SCAN_START_FAILED', ['reason' => 'SCAN_START_FAILED']);
			return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
		}
	}

	public function issueCaptureNonce($scanId, string $scanToken, int $actorEmployeeId, string $browserBinding): array
	{
		$lockName = '';
		try {
			$scan = $this->loadScan((int)$scanId, $scanToken, $actorEmployeeId);
			if (!$scan || !$this->config->enabled || !FaceSelectionGate::enabled($this->conn) || !$this->config->branchAllowed((int)$scan['branch_id'])
				|| !empty($scan['_owner_mismatch']) || (string)$scan['status'] !== 'ACTIVE'
				|| !preg_match('/^[a-f0-9]{64}$/', $browserBinding)
				|| !hash_equals((string)($scan['browser_session_hash'] ?? ''), $browserBinding)) {
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			$readiness = $this->readinessStatus();
			if (!$readiness['ready']) return $this->readinessRejection($readiness);
			if (!hash_equals((string)($scan['recognition_generation'] ?? ''), (string)($this->inference->providerMetadata()['recognition_generation'] ?? ''))) return $this->rejected('SYSTEM_NOT_READY', 'Recognition configuration changed');
			$lockName = 'face_scan_' . substr(hash('sha256', (string)$scanId), 0, 48);
			if (!$this->lock($lockName)) return $this->rejected('SCAN_BUSY', 'A recognition attempt is processing');
			$nonce = bin2hex(random_bytes(24));
			$hash = hash('sha256', $nonce);
			$this->run("UPDATE attendance_scan SET capture_nonce_hash = '{$hash}', capture_nonce_expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 SECOND), capture_nonce_used_at = NULL WHERE id = '" . (int)$scan['id'] . "' AND status = 'ACTIVE'");
			if ($this->conn->affected_rows !== 1) return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			return ['api_version' => 'v1', 'state' => 'READY', 'capture_nonce' => $nonce, 'expires_in' => 30];
		} catch (Throwable $exception) {
			return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
		} finally {
			if ($lockName !== '') $this->unlock($lockName);
		}
	}

	/** Refresh one active scan lease without changing its frozen roster identity. */
	public function renewFaceScan($scanId, string $scanToken, int $actorEmployeeId, string $browserBinding): array
	{
		$lockName = '';
		try {
			$scan = $this->loadScan((int)$scanId, $scanToken, $actorEmployeeId);
			if (!$scan || !empty($scan['_owner_mismatch']) || (string)$scan['status'] !== 'ACTIVE'
				|| !preg_match('/^[a-f0-9]{64}$/', $browserBinding)
				|| !hash_equals((string)($scan['browser_session_hash'] ?? ''), $browserBinding)) {
				return $this->rejected('SCAN_ACCESS', 'Scan lease is no longer available. Switch to manual attendance');
			}
			if (!$this->config->enabled || !FaceSelectionGate::enabled($this->conn) || !$this->config->branchAllowed((int)$scan['branch_id'])) {
				return $this->rejected('SYSTEM_NOT_READY', 'Scan lease cannot be renewed. Switch to manual attendance');
			}
			$lockName = 'face_scan_' . substr(hash('sha256', (string)$scanId), 0, 48);
			if (!$this->lock($lockName)) return $this->rejected('SCAN_BUSY', 'A capture is in progress', true, 'CHECK_STATUS');
			// A nonce marked used means PHP has already reserved an attempt. Never
			// replace its sidecar/nonce while that attempt may still be evaluating.
			if (!empty($scan['capture_nonce_used_at'])) return $this->rejected('SCAN_BUSY', 'A capture is in progress', true, 'CHECK_STATUS');
			$readiness = $this->readinessStatus();
			if (!$readiness['ready']) return $this->readinessRejection($readiness);
			$provider = $this->providerMetadata();
			if (!hash_equals((string)($scan['recognition_generation'] ?? ''), (string)($provider['recognition_generation'] ?? ''))
				|| (int)($scan['model_version_id'] ?? 0) !== (int)($provider['model_version_id'] ?? 0)) {
				return $this->rejected('SYSTEM_NOT_READY', 'Recognition configuration changed. Switch to manual attendance');
			}
			$candidates = $this->loadRenewalCandidates($scan);
			if (!$candidates) return $this->rejected('NO_MAPPED_CANDIDATES', 'No active face mappings remain. Switch to manual attendance');
			try {
				// Same private scan token and candidate refs preserve the frozen DB
				// roster binding while replacing the expiring in-memory sidecar lease.
				$this->inference->initializeScan((string)$scan['scan_token'], $candidates, $this->scanTtl);
			} catch (Throwable $exception) {
				FaceErrorLogger::record('FACE_SCAN_RENEWAL_INFERENCE_FAILED', ['scan_id' => (int)$scan['id']]);
				return $this->rejected('INFERENCE_UNAVAILABLE', 'Scan lease cannot be renewed. Switch to manual attendance');
			}
			$nonce = bin2hex(random_bytes(24));
			$hash = hash('sha256', $nonce);
			$this->run("UPDATE attendance_scan SET expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$this->scanTtl} SECOND), capture_nonce_hash = '{$hash}', capture_nonce_expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 SECOND), capture_nonce_used_at = NULL WHERE id = '" . (int)$scan['id'] . "' AND status = 'ACTIVE' AND capture_nonce_used_at IS NULL");
			if ($this->conn->affected_rows !== 1) return $this->rejected('SCAN_BUSY', 'A capture started while renewing', true, 'CHECK_STATUS');
			return ['api_version' => 'v1', 'state' => 'RENEWED', 'scan_id' => (int)$scan['id'],
				'scan_token' => (string)$scan['scan_token'], 'capture_nonce' => $nonce,
				'expires_in' => $this->scanTtl, 'roster_version' => (string)$scan['roster_version']] + $this->responseMode();
		} catch (Throwable $exception) {
			FaceErrorLogger::record('FACE_SCAN_RENEWAL_FAILED', ['scan_id' => (int)$scanId]);
			return $this->rejected('SYSTEM_NOT_READY', 'Scan lease cannot be renewed. Switch to manual attendance');
		} finally {
			if ($lockName !== '') $this->unlock($lockName);
		}
	}

	public function recognizeFaceAttempt($scanId, string $scanToken, int $actorEmployeeId, string $clientAttemptId, array $frames, string $challengeToken = '', array $captureContexts = [], string $captureNonce = '', string $browserBinding = '', ?float $clientZVariance = null): array
	{
		$lockName = '';
		$attemptLockName = '';
		$reservationId = 0;
		$recoveryMetadata = [];
		try {
			if (!$this->config->enabled) {
				return $this->rejected('FEATURE_DISABLED', 'Face attendance is disabled');
			}
			if (!FaceSelectionGate::enabled($this->conn)) {
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			if ((int)$scanId < 1 || $scanToken === '' || $actorEmployeeId < 1 || $clientAttemptId === '' || strlen($clientAttemptId) > 191 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $clientAttemptId)) {
				return $this->rejected('INVALID_REQUEST', 'Scan identifiers are required');
			}
			if (count($frames) !== 3) {
				return $this->rejected('FRAME_COUNT', 'Recognition requires exactly three frames');
			}
			foreach ($frames as $frame) {
				if (!is_string($frame) || $frame === '' || strlen($frame) > $this->maxFrameBytes) {
					return $this->rejected('INVALID_FRAME', 'Recognition frame is invalid or too large');
				}
			}
			if ($captureContexts && count($captureContexts) !== count($frames)) {
				return $this->rejected('INVALID_CAPTURE_CONTEXT', 'Capture geometry must match each recognition frame');
			}
			if ($challengeToken !== '' && !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $challengeToken)) {
				return $this->rejected('CHALLENGE_INVALID', 'Active challenge token is invalid');
			}
			$scan = $this->loadScan((int)$scanId, $scanToken, $actorEmployeeId);
			if (!$scan) {
				return $this->rejected('SCAN_ACCESS', 'Scan token, owner, or scan state is invalid');
			}
			if (!empty($scan['_owner_mismatch'])) {
				return $this->rejected('SCAN_OWNER', 'Only the scan owner may use this scan');
			}
			if ((string)$scan['status'] === 'EXPIRED') {
				return $this->rejected('SCAN_EXPIRED', 'Face scan has expired; start a new scan');
			}
			if ((string)$scan['status'] === 'CANCELLED') {
				return $this->rejected('SCAN_CANCELLED', 'Face scan was cancelled');
			}
			if ((string)$scan['status'] === 'COMPLETED') {
				return $this->rejected('SCAN_COMPLETED', 'Face scan was already completed');
			}
			if (!$this->browserMatches($scan, $browserBinding)) return $this->rejected('REJECT_REPLAY', 'Scan belongs to a different browser session');
			if (FaceAttendanceConfig::getEnv('FACE_CAPTURE_NONCE_REQUIRED') === '1') {
				$captureIds = array_map(function ($context) { return is_array($context) ? (string)($context['capture_id'] ?? '') : ''; }, $captureContexts);
				if (count($frames) !== 3 || count($captureIds) !== 3 || count(array_unique($captureIds)) !== 3
					|| in_array('', $captureIds, true) || !preg_match('/^[a-f0-9]{48}$/', $captureNonce)
					|| !preg_match('/^[a-f0-9]{64}$/', $browserBinding)
					|| !hash_equals((string)($scan['browser_session_hash'] ?? ''), $browserBinding)) {
					return $this->rejected('REJECT_REPLAY', 'Capture could not be verified. Continue with the attendance list');
				}
			}
			$fingerprint = $this->attemptFingerprint($scan, $actorEmployeeId, $browserBinding, $frames, $challengeToken, $captureContexts, $clientZVariance);
			$attemptLockName = 'face_attempt_' . substr(hash('sha256', $clientAttemptId), 0, 48);
			if (!$this->lock($attemptLockName)) return $this->rejected('SCAN_BUSY', 'This recognition attempt is already processing');
			$existing = $this->findAttempt($clientAttemptId);
			if ($existing) {
				$metadata = $this->attemptMetadata($existing);
				if ((int)$existing['scan_id'] !== (int)$scan['id'] || empty($metadata['fingerprint'])
					|| !hash_equals($metadata['fingerprint'], $fingerprint)) {
					return $this->rejected('IDEMPOTENCY_CONFLICT', 'The attempt identifier is bound to another request');
				}
				if (($metadata['state'] ?? '') !== 'RECORDED') {
					return $this->rejected('ATTEMPT_INCOMPLETE', 'Check scan status before capturing again', true, 'CHECK_STATUS');
				}
				return $this->storedAttemptResponse($scan, $existing);
			}
			$readiness = $this->readinessStatus();
			if (!$readiness['ready']) {
				if ($readiness['retryable']) return $this->readinessRejection($readiness);
				$this->run("UPDATE attendance_scan SET status = 'CANCELLED' WHERE id = '" . (int)$scan['id'] . "' AND status = 'ACTIVE'");
				$this->deleteSidecar((string)$scan['scan_token']);
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			$currentGeneration = (string)($this->inference->providerMetadata()['recognition_generation'] ?? '');
			if ($currentGeneration === '' || !hash_equals((string)($scan['recognition_generation'] ?? ''), $currentGeneration)) {
				$this->run("UPDATE attendance_scan SET status = 'CANCELLED' WHERE id = '" . (int)$scan['id'] . "' AND status = 'ACTIVE'");
				$this->deleteSidecar((string)$scan['scan_token']);
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			$lockName = 'face_scan_' . substr(hash('sha256', (string)$scanId), 0, 48);
			if (!$this->lock($lockName)) {
				return $this->rejected('SCAN_BUSY', 'Another recognition attempt is processing');
			}
			if (FaceAttendanceConfig::getEnv('FACE_CAPTURE_NONCE_REQUIRED') === '1') {
				$nonceHash = hash('sha256', $captureNonce);
				$this->run("UPDATE attendance_scan SET capture_nonce_used_at = CURRENT_TIMESTAMP WHERE id = '" . (int)$scan['id'] . "' AND actor_employee_id = '{$actorEmployeeId}' AND capture_nonce_hash = '{$nonceHash}' AND capture_nonce_used_at IS NULL AND capture_nonce_expires_at > CURRENT_TIMESTAMP AND status = 'ACTIVE'");
				if ($this->conn->affected_rows !== 1) {
					return $this->rejected('REJECT_REPLAY', 'Capture could not be verified. Continue with the attendance list');
				}
			}
			// Reserve the ID and fingerprint while holding both locks. Even an
			// interrupted inference must not allow that ID to name other pixels.
			$recoveryMetadata = ['fingerprint' => $fingerprint, 'state' => 'PROCESSING'];
			$pendingQuality = $this->escape(json_encode(['_camera_recovery' => $recoveryMetadata], JSON_UNESCAPED_SLASHES));
			$this->run("INSERT INTO recognition_attempt (scan_id, client_attempt_id, decision, outcome, quality_summary, liveness, model_version_id, recognition_generation) VALUES ('{$scan['id']}', '{$this->escape($clientAttemptId)}', 'REVIEW', 'SYSTEM_NOT_READY', '{$pendingQuality}', 'NOT_RUN', '" . (int)$scan['model_version_id'] . "', '{$this->escape((string)$scan['recognition_generation'])}')");
			$reservationId = (int)$this->conn->insert_id;
				if ($captureContexts) {
					$method = new ReflectionMethod($this->inference, 'recognizeAttempt');
					$inference = $method->getNumberOfParameters() >= 5
						? $this->inference->recognizeAttempt((string)$scan['scan_token'], $frames, $challengeToken, $captureContexts, $clientZVariance)
						: ($method->getNumberOfParameters() >= 4
							? $this->inference->recognizeAttempt((string)$scan['scan_token'], $frames, $challengeToken, $captureContexts)
							: $this->inference->recognizeAttempt((string)$scan['scan_token'], $frames, $challengeToken));
				} else {
					$method = new ReflectionMethod($this->inference, 'recognizeAttempt');
					$inference = $method->getNumberOfParameters() >= 5
						? $this->inference->recognizeAttempt((string)$scan['scan_token'], $frames, $challengeToken, [], $clientZVariance)
						: $this->inference->recognizeAttempt((string)$scan['scan_token'], $frames, $challengeToken);
				}
			$prepared = $this->prepareAttempt($inference);
			$scanStillActive = $this->one("SELECT id FROM attendance_scan WHERE id = '" . (int)$scan['id'] . "' AND status = 'ACTIVE' LIMIT 1");
			if (!$scanStillActive) {
				$this->deleteSidecar((string)$scan['scan_token']);
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			if (!hash_equals((string)$scan['recognition_generation'], (string)($this->inference->providerMetadata()['recognition_generation'] ?? ''))) {
				$this->run("UPDATE attendance_scan SET status = 'CANCELLED' WHERE id = '" . (int)$scan['id'] . "' AND status = 'ACTIVE'");
				$this->deleteSidecar((string)$scan['scan_token']);
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			$roster = null;
			if ($prepared['candidate_ref'] !== null) {
				$roster = $this->one("SELECT r.member_id, m.contact_id, r.candidate_ref, r.eligibility_status, CONCAT_WS(' ', NULLIF(TRIM(c.fname), ''), NULLIF(TRIM(c.lname), ''), NULLIF(TRIM(c.mname), '')) AS fullname FROM attendance_scan_roster r JOIN member m ON m.id = r.member_id JOIN contact c ON c.id = m.contact_id AND c.park = '0' WHERE r.scan_id = '{$scan['id']}' AND r.candidate_ref = '{$this->escape($prepared['candidate_ref'])}' AND r.eligibility_status = 'ELIGIBLE' LIMIT 1");
			}
			if ($prepared['decision'] === 'ACCEPT' && (!$roster || !$this->stillAutomaticCandidate($roster['member_id'], $scan, (int)$scan['model_version_id']))) {
				$prepared['decision'] = 'REVIEW';
				$prepared['outcome'] = 'SYSTEM_NOT_READY';
				$prepared['candidate_ref'] = null;
				$roster = null;
			}
			$candidateSql = $prepared['candidate_ref'] === null ? 'NULL' : "'{$this->escape($prepared['candidate_ref'])}'";
			$storedQuality = $prepared['quality'];
			$storedQuality['planar_spoof_flag'] = (bool)($prepared['planar_spoof_flag'] ?? false);
			$storedQuality['_camera_recovery'] = ['fingerprint' => $fingerprint, 'state' => 'RECORDED', 'details' => $prepared];
			$quality = $this->escape(json_encode($storedQuality, JSON_UNESCAPED_SLASHES));
			$modelVersion = (int)$scan['model_version_id'];
			$modelSql = $modelVersion > 0 ? (string)$modelVersion : 'NULL';
			$memberAlreadySelected = false;
			if ($prepared['decision'] === 'ACCEPT' && $prepared['candidate_ref'] !== null) {
				$priorAccepted = $this->one("SELECT id FROM recognition_attempt WHERE scan_id = '{$scan['id']}' AND candidate_ref = '{$this->escape($prepared['candidate_ref'])}' AND decision = 'ACCEPT' LIMIT 1");
				$memberAlreadySelected = (bool)$priorAccepted;
			}
			$this->run("UPDATE recognition_attempt SET candidate_ref = {$candidateSql}, decision = '{$prepared['decision']}', outcome = '{$this->escape($prepared['outcome'])}', top1_score = " . $this->decimal($prepared['top1_score']) . ", top2_score = " . $this->decimal($prepared['top2_score']) . ", margin = " . $this->decimal($prepared['margin']) . ", quality_summary = '{$quality}', liveness = '{$this->escape($prepared['liveness'])}', model_version_id = {$modelSql}, latency_ms = '{$prepared['latency_ms']}' WHERE id = '{$reservationId}'");
			$scanAttemptId = $reservationId;
			$reservationId = 0;

			if ($scanAttemptId > 0 && !empty($prepared['evidence_summary']) && is_array($prepared['evidence_summary'])) {
				$this->recordCaptureEvidence(null, $scanAttemptId, $prepared['evidence_summary'], $modelVersion, (string)($scan['calibration_policy_version'] ?: 'face-policy-v2'), 'SCAN');
			}
			$attendanceResult = null;
			if ($this->automaticAttendanceEnabled() && $prepared['decision'] === 'ACCEPT' && $roster) {
				$attendanceResult = $this->recordAcceptedFaceAttendance($scan, $roster, $clientAttemptId);
				$memberAlreadySelected = ($attendanceResult['state'] ?? '') === 'ALREADY_MARKED';
			}
			return $this->attemptResponse($scan, $prepared['decision'], $prepared['candidate_ref'], $prepared['latency_ms'], false, $roster, $attendanceResult, $prepared, $memberAlreadySelected);
		} catch (FaceInferenceOverloadedException $exception) {
			FaceErrorLogger::record('FACE_INFERENCE_OVERLOADED', [
				'scan_id' => (int)$scanId,
				'reason' => 'CONCURRENCY_LIMIT_EXCEEDED',
			]);
			return $this->rejected('INFERENCE_BUSY', 'Inference service at capacity (maximum 4 concurrent requests), retrying automatically...');
		} catch (FaceInferenceUnavailableException $exception) {
			FaceErrorLogger::record('FACE_INFERENCE_OFFLINE', [
				'scan_id' => (int)$scanId,
				'reason' => 'INFERENCE_UNAVAILABLE',
			]);
			return $this->rejected('INFERENCE_OFFLINE', 'Face recognition service temporarily unavailable. Please retry or use manual attendance.');
			} catch (FaceInferenceTimeoutException $exception) {
			FaceErrorLogger::record('FACE_INFERENCE_TIMEOUT', [
				'scan_id' => (int)$scanId,
				'reason' => 'INFERENCE_TIMEOUT',
			]);
				return $this->rejected('INFERENCE_TIMEOUT', 'Face recognition request timed out. Retrying...');
			} catch (FaceInferenceException $exception) {
				$reason = !empty($exception->reason) ? (string)$exception->reason : 'INFERENCE_FAILED';
				FaceErrorLogger::record('FACE_INFERENCE_REJECTED', [
					'scan_id' => (int)$scanId,
					'reason' => $reason,
				]);
				return $this->rejected($reason, $exception->getMessage());
			} catch (Throwable $exception) {
			FaceErrorLogger::record('FACE_ATTEMPT_FAILED', [
				'scan_id' => (int)$scanId,
				'reason' => 'ATTEMPT_FAILED',
			]);
			return $this->rejected('ATTEMPT_FAILED', 'Recognition attempt could not be recorded');
		} finally {
			if ($reservationId > 0) {
				try {
					$recoveryMetadata['state'] = 'INCOMPLETE';
					$quality = $this->escape(json_encode(['_camera_recovery' => $recoveryMetadata], JSON_UNESCAPED_SLASHES));
					$this->run("UPDATE recognition_attempt SET quality_summary = '{$quality}' WHERE id = '{$reservationId}'");
				} catch (Throwable $ignored) {}
			}

			if ($lockName !== '') {
				$this->unlock($lockName);
			}
			if ($attemptLockName !== '') {
				$this->unlock($attemptLockName);
			}
		}
	}

	/** Optional provider capability; false legacy readiness remains terminal. */
	public function readinessStatus(): array
	{
		if (is_callable([$this->inference, 'readinessStatus'])) {
			$status = $this->inference->readinessStatus();
			if (($status['ready'] ?? false) === true) return ['ready' => true, 'retryable' => false, 'reason' => 'READY'];
			$reason = (string)($status['reason'] ?? 'SYSTEM_NOT_READY');
			if (($status['retryable'] ?? false) === true && in_array($reason, ['INFERENCE_BUSY', 'INFERENCE_OFFLINE', 'INFERENCE_TIMEOUT'], true)) {
				return ['ready' => false, 'retryable' => true, 'reason' => $reason];
			}
			return ['ready' => false, 'retryable' => false, 'reason' => 'SYSTEM_NOT_READY'];
		}
		return ['ready' => $this->inference->checkReadiness(), 'retryable' => false, 'reason' => 'SYSTEM_NOT_READY'];
	}

	private function readinessRejection(array $status): array
	{
		return $this->rejected($status['reason'], 'Face scanning is temporarily unavailable. Please wait or use the attendance list.', $status['retryable'], $status['retryable'] ? 'CHECK_STATUS' : 'STOP');
	}

	private function browserMatches(array $scan, string $binding): bool
	{
		$expected = (string)($scan['browser_session_hash'] ?? '');
		return $expected === '' ? FaceAttendanceConfig::getEnv('FACE_CAPTURE_NONCE_REQUIRED') !== '1' : hash_equals($expected, $binding);
	}

	private function attemptFingerprint(array $scan, int $actor, string $binding, array $frames, string $challenge, array $contexts, ?float $clientZVariance = null): string
	{
		$canonical = function ($value) use (&$canonical) {
			if (!is_array($value)) return $value;
			if ($value && array_keys($value) !== range(0, count($value) - 1)) ksort($value);
			return array_map($canonical, $value);
		};
		return hash('sha256', json_encode($canonical(['version' => 1, 'scan' => (int)$scan['id'], 'actor' => $actor,
			'browser' => $binding, 'frames' => array_map(function ($frame) { return hash('sha256', $frame); }, $frames),
			'challenge' => $challenge, 'contexts' => $contexts, 'client_z_variance' => $clientZVariance]), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	private function findAttempt(string $id): ?array
	{
		return $this->one("SELECT id, scan_id, client_attempt_id, decision, outcome, candidate_ref, top1_score, top2_score, margin, quality_summary, liveness, model_version_id, latency_ms FROM recognition_attempt WHERE client_attempt_id = '{$this->escape($id)}' LIMIT 1") ?: null;
	}

	private function attemptMetadata(array $attempt): array
	{
		$quality = json_decode((string)($attempt['quality_summary'] ?? ''), true);
		return is_array($quality['_camera_recovery'] ?? null) ? $quality['_camera_recovery'] : [];
	}

	private function storedAttemptResponse(array $scan, array $existing): array
	{
		$metadata = $this->attemptMetadata($existing);
		$details = $metadata['details'] ?? $existing;
		$roster = null;
		if (!empty($existing['candidate_ref'])) {
			$roster = $this->one("SELECT r.member_id, m.contact_id, r.candidate_ref, r.eligibility_status, CONCAT_WS(' ', NULLIF(TRIM(c.fname), ''), NULLIF(TRIM(c.lname), ''), NULLIF(TRIM(c.mname), '')) AS fullname FROM attendance_scan_roster r JOIN member m ON m.id = r.member_id JOIN contact c ON c.id = m.contact_id AND c.park = '0' WHERE r.scan_id = '{$scan['id']}' AND r.candidate_ref = '{$this->escape($existing['candidate_ref'])}' LIMIT 1");
		}
		$responseDecision = (string)$existing['decision'];
		$responseCandidateRef = $existing['candidate_ref'] !== null ? (string)$existing['candidate_ref'] : null;
		if ($responseDecision === 'ACCEPT' && (!$roster || !$this->stillAutomaticCandidate($roster['member_id'], $scan, (int)$scan['model_version_id']))) {
			$responseDecision = 'REVIEW';
			$responseCandidateRef = null;
			$roster = null;
		}
		$memberAlreadySelected = false;
		if ($responseDecision === 'ACCEPT' && $responseCandidateRef !== null) {
			$earlier = $this->one("SELECT id FROM recognition_attempt WHERE scan_id = '{$scan['id']}' AND candidate_ref = '{$this->escape($existing['candidate_ref'])}' AND decision = 'ACCEPT' AND id < '{$existing['id']}' LIMIT 1");
			$memberAlreadySelected = (bool)$earlier;
		}
		$attendanceResult = null;
		if ($this->automaticAttendanceEnabled() && $responseDecision === 'ACCEPT' && $roster) {
			$attendanceResult = $this->recordAcceptedFaceAttendance($scan, $roster, (string)$existing['client_attempt_id']);
			$memberAlreadySelected = ($attendanceResult['state'] ?? '') === 'ALREADY_MARKED';
		}
		return $this->attemptResponse($scan, $responseDecision, $responseCandidateRef, (int)$existing['latency_ms'], true, $roster, $attendanceResult, $details, $memberAlreadySelected);
	}

	private function lookupAttempt(array $scan, string $id): array
	{
		if ((string)$scan['status'] !== 'ACTIVE') return ['attempt' => ['state' => 'UNAVAILABLE'], 'retryable' => false, 'retry_after_ms' => 0, 'recovery_action' => 'STOP'];
		$lock = 'face_attempt_' . substr(hash('sha256', $id), 0, 48);
		if (!$this->lock($lock)) return ['attempt' => ['state' => 'PROCESSING', 'client_attempt_id' => $id], 'retryable' => true, 'retry_after_ms' => 1000, 'recovery_action' => 'CHECK_STATUS'];
		try {
			$attempt = $this->findAttempt($id);
			if ($attempt && (int)$attempt['scan_id'] !== (int)$scan['id']) return ['attempt' => ['state' => 'UNAVAILABLE'], 'retryable' => false, 'retry_after_ms' => 0, 'recovery_action' => 'STOP'];
			$metadata = $attempt ? $this->attemptMetadata($attempt) : [];
			if ($attempt && (!$metadata || ($metadata['state'] ?? '') === 'RECORDED')) {
				return ['attempt' => $this->storedAttemptResponse($scan, $attempt) + ['client_attempt_id' => $id], 'retryable' => false, 'retry_after_ms' => 0, 'recovery_action' => 'STOP'];
			}
			// No worker owns this ID, so an abandoned reservation cannot complete.
			// Require a fresh burst and a NEW ID, never resend uncertain pixels.
			$ready = $this->readinessStatus();
			return ['attempt' => ['state' => 'NOT_FOUND', 'client_attempt_id' => $id],
				'retryable' => $ready['ready'] || $ready['retryable'], 'retry_after_ms' => 1000,
				'recovery_action' => $ready['ready'] ? 'RECAPTURE' : ($ready['retryable'] ? 'CHECK_STATUS' : 'STOP')];
		} finally { $this->unlock($lock); }
	}

	public function completeFaceScan($scanId, string $scanToken, int $actorEmployeeId, bool $confirmAbsent = false): array
	{
		try {
			$scan = $this->loadScan((int)$scanId, $scanToken, $actorEmployeeId);
			if (!$scan) {
				return $this->rejected('SCAN_ACCESS', 'Scan token, owner, or scan state is invalid');
			}
			if (!empty($scan['_owner_mismatch'])) {
				return $this->rejected('SCAN_OWNER', 'Only the scan owner may use this scan');
			}
			if ((string)$scan['status'] === 'EXPIRED') {
				return ['api_version' => 'v1', 'state' => 'EXPIRED', 'scan_id' => (int)$scan['id'], 'attendance_untouched' => true, 'pad_status' => 'DEFERRED'] + $this->responseMode();
			}
			if ((string)$scan['status'] === 'CANCELLED') {
				return ['api_version' => 'v1', 'state' => 'CANCELLED', 'scan_id' => (int)$scan['id'], 'attendance_untouched' => true, 'pad_status' => 'DEFERRED'] + $this->responseMode();
			}
			if ($confirmAbsent) {
				return $this->rejected('ABSENCE_CONFIRMATION_UNSUPPORTED', 'Face scanning records present attendance only');
			}
			$matched = [];
			$reviewed = [];
			$attempts = $this->run("SELECT candidate_ref, decision FROM recognition_attempt WHERE scan_id = '{$scan['id']}'");
			while ($attempt = $attempts->fetch_assoc()) {
				if ($attempt['decision'] === 'ACCEPT' && $attempt['candidate_ref'] !== null) {
					$matched[$attempt['candidate_ref']] = true;
				} elseif ($attempt['decision'] === 'REVIEW' && $attempt['candidate_ref'] !== null) {
					$reviewed[$attempt['candidate_ref']] = true;
				}
			}
			$rows = $this->run("SELECT r.member_id, r.candidate_ref, r.consent_status, r.template_count, CONCAT_WS(' ', NULLIF(TRIM(c.fname), ''), NULLIF(TRIM(c.lname), ''), NULLIF(TRIM(c.mname), '')) AS fullname FROM attendance_scan_roster r JOIN member m ON m.id = r.member_id JOIN contact c ON c.id = m.contact_id AND c.park = '0' WHERE r.scan_id = '{$scan['id']}' ORDER BY r.member_id");
			$matchedList = $manualList = $missingList = $reviewList = [];
			while ($row = $rows->fetch_assoc()) {
				$item = ['member_id' => (int)$row['member_id'], 'fullname' => $row['fullname']];
				if ($row['candidate_ref'] !== '' && isset($matched[$row['candidate_ref']])) {
					if ($this->stillAutomaticCandidate((int)$row['member_id'], $scan, (int)$scan['model_version_id'])) {
						$matchedList[] = $item;
					} else {
						$reviewList[] = $item;
					}
				} elseif ($row['candidate_ref'] !== '' && isset($reviewed[$row['candidate_ref']])) {
					$reviewList[] = $item;
				} elseif ((int)$row['template_count'] > 0 && $row['consent_status'] === 'PROGRAM_AUTHORIZED') {
					$missingList[] = $item;
				} else {
					$manualList[] = $item;
				}
			}
			$this->run("UPDATE attendance_scan SET status = 'COMPLETED', completed_at = CURRENT_TIMESTAMP WHERE id = '{$scan['id']}' AND status = 'ACTIVE'");
			$this->deleteSidecar((string)$scan['scan_token']);
			return ['api_version' => 'v1', 'state' => 'COMPLETED', 'scan_id' => (int)$scan['id'], 'matched' => $matchedList, 'manual' => $manualList, 'missing' => $missingList, 'review' => $reviewList, 'absence_written' => false, 'attendance_written' => $this->automaticAttendanceEnabled() && $this->attendanceWrittenForScan($scan), 'pad_status' => 'DEFERRED'] + $this->responseMode();
		} catch (Throwable $exception) {
			return $this->rejected('COMPLETE_FAILED', 'Scan could not be completed');
		}
	}

	public function getFaceScanStatus($scanId, string $scanToken, int $actorEmployeeId, string $clientAttemptId = '', string $browserBinding = ''): array
	{
		try {
			$scan = $this->loadScan((int)$scanId, $scanToken, $actorEmployeeId);
			if (!$scan) {
				return $this->rejected('SCAN_ACCESS', 'Scan token, owner, or scan state is invalid');
			}
			if (!empty($scan['_owner_mismatch'])) {
				return $this->rejected('SCAN_OWNER', 'Only the scan owner may use this scan');
			}
			if (!FaceSelectionGate::enabled($this->conn)) {
				return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
			}
			if ($clientAttemptId !== '' && (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/', $clientAttemptId)
				|| !$this->browserMatches($scan, $browserBinding))) return $this->rejected('SCAN_ACCESS', 'Attempt lookup is not authorized');
			$lookup = $clientAttemptId !== '' ? $this->lookupAttempt($scan, $clientAttemptId) : [];
			$count = $this->one("SELECT COUNT(*) AS total FROM recognition_attempt WHERE scan_id = '{$scan['id']}'");
			$selected = [];
			$date = $this->escape((string)$scan['attendance_date']);
			$selectedRows = $this->run("SELECT DISTINCT r.member_id, m.contact_id, CONCAT_WS(' ', NULLIF(TRIM(c.fname), ''), NULLIF(TRIM(c.lname), ''), NULLIF(TRIM(c.mname), '')) AS fullname FROM recognition_attempt a JOIN attendance_scan_roster r ON r.scan_id = a.scan_id AND r.candidate_ref = a.candidate_ref JOIN member m ON m.id = r.member_id AND m.bid = '{$scan['branch_id']}' AND m.status = '1' AND m.park = '0' JOIN contact c ON c.id = m.contact_id AND c.park = '0' WHERE a.scan_id = '{$scan['id']}' AND a.decision = 'ACCEPT' AND r.eligibility_status = 'ELIGIBLE' AND EXISTS (SELECT 1 FROM invoice_invoiceitem_module_view i WHERE i.contact_id = m.contact_id AND i.batch_id = '{$scan['batch_id']}' AND i.park = '0' AND i.item_park = '0' AND i.start_date <= '{$date}' AND i.end_date >= '{$date}' AND (i.sessions = '0' OR i.sessions > i.sessions_used)) AND EXISTS (SELECT 1 FROM biometric_consent bc WHERE bc.member_id = m.id AND bc.consent_status = 'PROGRAM_AUTHORIZED') AND EXISTS (SELECT 1 FROM face_template ft JOIN face_model_version fmv ON fmv.id = ft.model_version_id AND fmv.embedding_dimension = ft.embedding_dimension WHERE ft.member_id = m.id AND ft.status = 'ACTIVE' AND ft.model_version_id = '{$scan['model_version_id']}') ORDER BY r.member_id");
			while ($row = $selectedRows->fetch_assoc()) {
				$selected[] = [
					'member_id' => (int)$row['member_id'],
					'contact_id' => (int)$row['contact_id'],
					'member_name' => (string)$row['fullname'],
				];
			}
			return $lookup + [
				'retryable' => false, 'retry_after_ms' => 0, 'recovery_action' => 'STOP',
				'api_version' => 'v1',
				'state' => (string)$scan['status'],
				'scan_id' => (int)$scan['id'],
				'roster_version' => (string)$scan['roster_version'],
				'attempt_count' => (int)($count['total'] ?? 0),
				'selected' => $selected,
				'expires_at' => (string)$scan['expires_at'],
				'provider' => $this->scanProviderMetadata($scan),
				'pad_status' => 'DEFERRED',
			] + $this->responseMode();
		} catch (Throwable $exception) {
			return $this->rejected('STATUS_FAILED', 'Scan status could not be loaded');
		}
	}

	public function cancelFaceScan($scanId, string $scanToken, int $actorEmployeeId): array
	{
		try {
			$scan = $this->loadScan((int)$scanId, $scanToken, $actorEmployeeId);
			if (!$scan) {
				return $this->rejected('SCAN_ACCESS', 'Scan token, owner, or scan state is invalid');
			}
			if (!empty($scan['_owner_mismatch'])) {
				return $this->rejected('SCAN_OWNER', 'Only the scan owner may use this scan');
			}
			if ((string)$scan['status'] === 'EXPIRED') {
				return ['api_version' => 'v1', 'state' => 'EXPIRED', 'scan_id' => (int)$scan['id'], 'attendance_untouched' => true, 'pad_status' => 'DEFERRED'] + $this->responseMode();
			}
			if ((string)$scan['status'] === 'CANCELLED') {
				return ['api_version' => 'v1', 'state' => 'CANCELLED', 'scan_id' => (int)$scan['id'], 'attendance_untouched' => true, 'pad_status' => 'DEFERRED'] + $this->responseMode();
			}
			if ((string)$scan['status'] === 'COMPLETED') {
				return ['api_version' => 'v1', 'state' => 'COMPLETED', 'scan_id' => (int)$scan['id'], 'attendance_untouched' => true, 'pad_status' => 'DEFERRED'] + $this->responseMode();
			}
			$this->run("UPDATE attendance_scan SET status = 'CANCELLED', cancelled_at = CURRENT_TIMESTAMP WHERE id = '{$scan['id']}' AND status = 'ACTIVE'");
			$this->deleteSidecar((string)$scan['scan_token']);
			return ['api_version' => 'v1', 'state' => 'CANCELLED', 'scan_id' => (int)$scan['id'], 'attendance_untouched' => true, 'pad_status' => 'DEFERRED'] + $this->responseMode();
		} catch (Throwable $exception) {
			return $this->rejected('CANCEL_FAILED', 'Scan could not be cancelled');
		}
	}

	private function normalizeStart(array $request): array
	{
		$attendanceSessionId = $request['attendance_session_id'] ?? ($request['s_id'] ?? ($request['session_id'] ?? null));
		$fields = ['branch_id', 'batch_id', 'attendance_date', 'selected_time', 'event_type', 'actor_employee_id'];
		foreach ($fields as $field) {
			if (!isset($request[$field]) || $request[$field] === '') {
				throw new InvalidArgumentException("Missing scan field: {$field}");
			}
		}
		if ($attendanceSessionId === null || $attendanceSessionId === '') {
			throw new InvalidArgumentException('Missing scan field: attendance_session_id');
		}
		$date = (string)$request['attendance_date'];
		$time = (string)$request['selected_time'];
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $time)) {
			throw new InvalidArgumentException('Invalid date or time format');
		}
		$dateObject = DateTime::createFromFormat('!Y-m-d', $date);
		$timeObject = DateTime::createFromFormat('!H:i:s', strlen($time) === 5 ? $time . ':00' : $time);
		if (!$dateObject || $dateObject->format('Y-m-d') !== $date || !$timeObject || $timeObject->format('H:i:s') !== (strlen($time) === 5 ? $time . ':00' : $time)) {
			throw new InvalidArgumentException('Invalid date or time value');
		}
		$ids = array_map('intval', [$request['branch_id'], $request['batch_id'], $attendanceSessionId, $request['actor_employee_id']]);
		// session_id = 0 is the valid legacy value for a non-session batch.
		// Branch, batch, and actor identifiers must still be positive.
		if ($ids[0] < 1 || $ids[1] < 1 || $ids[2] < 0 || $ids[3] < 1) {
			throw new InvalidArgumentException('Scan identifiers must be positive');
		}
		$event = strtoupper((string)$request['event_type']);
		if (!in_array($event, ['PUNCH_IN', 'PUNCH_OUT'], true)) {
			throw new InvalidArgumentException('Invalid scan event type');
		}
		return ['branch_id' => $ids[0], 'batch_id' => $ids[1], 'attendance_date' => $date, 'session_id' => $ids[2], 'selected_time' => strlen($time) === 5 ? $time . ':00' : $time, 'event_type' => $event, 'actor_employee_id' => $ids[3], 'check_month' => !empty($request['check_month'])];
	}

	private function scheduledBatchIsValid(array $command): bool
	{
		$date = $this->escape($command['attendance_date']);
		$batch = $this->one("SELECT id FROM batch WHERE id = '{$command['batch_id']}' AND bid = '{$command['branch_id']}' AND park = '0' AND inactive = '0' AND start_date <= '{$date}' AND end_date >= '{$date}' LIMIT 1");
		if (!$batch) {
			return false;
		}
		$day = (int)date('w', strtotime($command['attendance_date']));
		$time = $this->escape($command['selected_time']);
		return (bool)$this->one("SELECT id FROM batch_time WHERE batch_id = '{$command['batch_id']}' AND day_code = '{$day}' AND start_time <= '{$time}' AND end_time >= '{$time}' LIMIT 1");
	}

	/** Rehydrate only the original roster/candidate references for lease renewal. */
	private function loadRenewalCandidates(array $scan): array
	{
		$scanId = (int)($scan['id'] ?? 0);
		$modelVersionId = (int)($scan['model_version_id'] ?? 0);
		if ($scanId < 1 || $modelVersionId < 1) return [];
		$rows = $this->run("SELECT member_id, candidate_ref, consent_status, template_count FROM attendance_scan_roster WHERE scan_id = '{$scanId}' AND eligibility_status = 'ELIGIBLE' ORDER BY member_id");
		$candidates = [];
		while ($row = $rows->fetch_assoc()) {
			if ((string)$row['consent_status'] !== 'PROGRAM_AUTHORIZED' || (int)$row['template_count'] < 1 || !preg_match('/^[a-f0-9]{32}$/', (string)$row['candidate_ref'])) continue;
			$embeddings = $this->loadEmbeddings((int)$row['member_id'], $modelVersionId);
			if ($embeddings) $candidates[] = ['candidate_ref' => (string)$row['candidate_ref'], 'embeddings' => array_values($embeddings)];
		}
		return $candidates;
	}

	private function loadRoster(array $command, int $modelVersionId): array
	{
		$date = $this->escape($command['attendance_date']);
		$result = $this->run("SELECT DISTINCT m.id, m.contact_id, CONCAT_WS(' ', NULLIF(TRIM(c.fname), ''), NULLIF(TRIM(c.lname), ''), NULLIF(TRIM(c.mname), '')) AS fullname, CASE WHEN EXISTS (SELECT 1 FROM biometric_consent bc WHERE bc.member_id = m.id AND bc.consent_status = 'PROGRAM_AUTHORIZED') THEN 'PROGRAM_AUTHORIZED' ELSE 'NONE' END AS consent_status, (SELECT COUNT(*) FROM face_template ft WHERE ft.member_id = m.id AND ft.status = 'ACTIVE' AND ft.model_version_id = '{$modelVersionId}') AS template_count FROM member m JOIN contact c ON c.id = m.contact_id AND c.park = '0' JOIN invoice_invoiceitem_module_view i ON i.contact_id = m.contact_id AND i.batch_id = '{$command['batch_id']}' WHERE m.bid = '{$command['branch_id']}' AND m.status = '1' AND m.park = '0' AND i.park = '0' AND i.item_park = '0' AND i.start_date <= '{$date}' AND i.end_date >= '{$date}' AND (i.sessions = '0' OR i.sessions > i.sessions_used) ORDER BY m.id");
		$members = [];
		while ($row = $result->fetch_assoc()) {
			$members[] = $row;
		}
		return $members;
	}

	private function loadEmbeddings(int $memberId, int $modelVersionId): array
	{
		$result = $this->run("SELECT ft.id, ft.encrypted_embedding, ft.embedding_dimension, ft.preprocessing_version, fmv.embedding_dimension AS expected_dimension, fmv.preprocessing_version AS expected_preprocessing FROM face_template ft LEFT JOIN face_model_version fmv ON fmv.id = ft.model_version_id WHERE ft.member_id = '{$memberId}' AND ft.status = 'ACTIVE' AND ft.model_version_id = '{$modelVersionId}' ORDER BY ft.id");
		$embeddings = [];
		while ($row = $result->fetch_assoc()) {
			try {
				$embedding = $this->cipher->decrypt((string)$row['encrypted_embedding']);
				if (!is_array($embedding) || !$row['expected_dimension'] || count($embedding) !== (int)$row['expected_dimension'] || (int)$row['embedding_dimension'] !== (int)$row['expected_dimension']
					|| !$row['preprocessing_version'] || !$row['expected_preprocessing'] || (string)$row['preprocessing_version'] !== (string)$row['expected_preprocessing']) {
					throw new RuntimeException('Invalid active template dimension or model binding');
				}
				foreach ($embedding as $value) {
					if (!is_numeric($value) || !is_finite((float)$value)) {
						throw new RuntimeException('Invalid active template value');
					}
				}
				$embeddings[] = $embedding;
			} catch (Throwable $exception) {
				$this->run("UPDATE face_template SET status = 'QUARANTINED' WHERE id = '" . (int)$row['id'] . "' AND status = 'ACTIVE'");
				FaceErrorLogger::record('FACE_TEMPLATE_QUARANTINED', ['member_id' => $memberId, 'reason' => 'INVALID_ACTIVE_TEMPLATE']);
				throw new RuntimeException('Invalid active template; scan cannot continue', 0, $exception);
			}
		}
		// Guided enrollment contributes five poses plus one independent holdout.
		// Keep each stored vector; legacy rosters still contain their original three.
		return array_slice($embeddings, 0, FaceEnrollmentService::GUIDED_TEMPLATE_COUNT);
	}

	private function averageEmbedding(array $embeddings): array
	{
		if (empty($embeddings) || !isset($embeddings[0]) || !is_array($embeddings[0])) {
			return [];
		}
		$dimension = count($embeddings[0]);
		if ($dimension < 1) {
			return [];
		}
		$totals = array_fill(0, $dimension, 0.0);
		$count = 0;
		foreach ($embeddings as $embedding) {
			if (!is_array($embedding) || count($embedding) !== $dimension) {
				continue;
			}
			foreach ($embedding as $index => $value) {
				$totals[$index] += (float)$value;
			}
			$count++;
		}
		if ($count < 1) {
			return [];
		}
		return array_map(function ($value) use ($count) {
			return $value / $count;
		}, $totals);
	}

	private function loadScan(int $scanId, string $token, int $actor)
	{
		$scan = $this->one("SELECT * FROM attendance_scan WHERE id = '{$scanId}' AND scan_token = '{$this->escape($token)}' AND status IN ('ACTIVE', 'COMPLETED', 'CANCELLED', 'EXPIRED') LIMIT 1");
		if ($scan && (string)$scan['status'] === 'ACTIVE' && strtotime((string)$scan['expires_at']) <= time()) {
			$this->run("UPDATE attendance_scan SET status = 'EXPIRED' WHERE id = '{$scan['id']}' AND status = 'ACTIVE'");
			$scan['status'] = 'EXPIRED';
			$this->deleteSidecar((string)$scan['scan_token']);
		}
		if ($scan && (int)$scan['actor_employee_id'] !== $actor) {
			$scan['_owner_mismatch'] = true;
		}
		return $scan;
	}

	private function stillAutomaticCandidate($memberId, array $scan, int $modelVersionId): bool
	{
		$date = $this->escape((string)$scan['attendance_date']);
		$row = $this->one("SELECT m.id FROM member m WHERE m.id = '{$memberId}' AND m.bid = '{$scan['branch_id']}' AND m.status = '1' AND m.park = '0' AND EXISTS (SELECT 1 FROM invoice_invoiceitem_module_view i WHERE i.contact_id = m.contact_id AND i.batch_id = '{$scan['batch_id']}' AND i.park = '0' AND i.item_park = '0' AND i.start_date <= '{$date}' AND i.end_date >= '{$date}' AND (i.sessions = '0' OR i.sessions > i.sessions_used)) AND EXISTS (SELECT 1 FROM biometric_consent bc WHERE bc.member_id = m.id AND bc.consent_status = 'PROGRAM_AUTHORIZED') AND EXISTS (SELECT 1 FROM face_template ft JOIN face_model_version fmv ON fmv.id = ft.model_version_id AND fmv.embedding_dimension = ft.embedding_dimension WHERE ft.member_id = m.id AND ft.status = 'ACTIVE' AND ft.model_version_id = '{$modelVersionId}') LIMIT 1");
		return (bool)$row;
	}

	private function automaticAttendanceEnabled(): bool
	{
		return $this->config->liveWrite && !$this->config->shadowMode;
	}

	private function responseMode(): array
	{
		$liveWrite = $this->automaticAttendanceEnabled();
		return [
			'commit_mode' => $liveWrite ? 'AUTO_PRESENT' : 'TEACHER_REVIEW',
			'shadow_mode' => (bool)$this->config->shadowMode,
			'live_write' => $liveWrite,
		];
	}

	private function recordAcceptedFaceAttendance(array $scan, array $roster, string $clientAttemptId): array
	{
		// AttendanceDomainService uses the established attendance contact identifier
		// for its legacy attendance and event keys. The face member identifier remains
		// bound by the frozen scan roster and recognition_attempt.
		return $this->attendance->recordMemberAttendance([
			'branch_id' => (int)$scan['branch_id'],
			'batch_id' => (int)$scan['batch_id'],
			'attendance_date' => (string)$scan['attendance_date'],
			'session_id' => (int)$scan['session_id'],
			'member_id' => (int)$roster['contact_id'],
			'person_type' => 'M',
			'negative' => 0,
			'source' => 'FACE_MATCH',
			'event_type' => (string)$scan['event_type'],
			'actor_employee_id' => (int)$scan['actor_employee_id'],
			'client_attempt_id' => $clientAttemptId,
			'scan_id' => (int)$scan['id'],
			'check_month' => true,
		]);
	}

	private function attendanceWrittenForScan(array $scan): bool
	{
		$row = $this->one("SELECT id FROM attendance_event WHERE scan_id = '" . (int)$scan['id'] . "' AND source = 'FACE_MATCH' LIMIT 1");
		return (bool)$row;
	}

	private function prepareAttempt(array $result): array
	{
			$identityDecision = strtoupper((string)($result['identity_decision'] ?? ($result['decision'] ?? 'REJECT')));
			$decision = strtoupper((string)($result['decision'] ?? 'REJECT'));
		if (!in_array($decision, ['ACCEPT', 'REVIEW', 'REJECT'], true)) {
			$decision = 'REVIEW';
		}
		// The shadow pilot may collect evidence; teacher row selection requires
		// a separate operator-controlled release gate after acceptance testing.
		$teacherSelFlag = FaceAttendanceConfig::getEnv('FACE_TEACHER_SELECTION_ENABLED');
		if ($decision === 'ACCEPT' && (string)$teacherSelFlag !== '1') {
			$decision = 'REVIEW';
			$result['outcome'] = 'SYSTEM_NOT_READY';
			$result['reason_code'] = 'TEACHER_SELECTION_DISABLED';
		}
		$liveness = strtoupper((string)($result['liveness'] ?? 'UNKNOWN'));
		if (!in_array($liveness, ['LIVE', 'SPOOF', 'CHALLENGE', 'UNKNOWN', 'NOT_RUN'], true)) {
			$liveness = 'UNKNOWN';
		}
		if ($decision === 'ACCEPT' && $liveness !== 'LIVE') {
			$decision = $liveness === 'SPOOF' ? 'REJECT' : 'REVIEW';
		}
		$outcome = strtoupper((string)($result['outcome'] ?? ''));
		if (!in_array($outcome, ['ACCEPT', 'UNKNOWN', 'AMBIGUOUS', 'REJECT_QUALITY', 'PAD_CHALLENGE', 'REJECT_PAD', 'REJECT_REPLAY', 'SYSTEM_NOT_READY'], true)) {
			$reason = strtoupper((string)($result['reason_code'] ?? ''));
			$outcome = $decision === 'ACCEPT' ? 'ACCEPT' : ($liveness === 'CHALLENGE' ? 'PAD_CHALLENGE' : ($liveness === 'SPOOF' ? 'REJECT_PAD' : (in_array($reason, ['AMBIGUOUS_IDENTITY', 'IDENTITY_CONSENSUS_FAILED', 'MULTIPLE_FACES_IN_GUIDE'], true) ? 'AMBIGUOUS' : (in_array(strtoupper((string)($result['stage'] ?? '')), ['QUALITY', 'DETECTION', 'GUIDE_SELECTION'], true) ? 'REJECT_QUALITY' : 'UNKNOWN'))));
		}
		if ($outcome !== 'ACCEPT' || $decision !== 'ACCEPT') {
			$decision = $decision === 'ACCEPT' ? 'REVIEW' : $decision;
			if ($outcome === 'ACCEPT') $outcome = 'UNKNOWN';
		}
		$candidateRef = isset($result['candidate_ref']) && $result['candidate_ref'] !== '' ? (string)$result['candidate_ref'] : null;
			if ($decision !== 'ACCEPT') {
				$candidateRef = null;
			}
		$livenessScore = isset($result['liveness_score']) && is_numeric($result['liveness_score']) ? (float)$result['liveness_score'] : null;
		if ($livenessScore !== null && ($livenessScore < 0.0 || $livenessScore > 1.0)) {
			$livenessScore = null;
		}
		$livenessReason = strtoupper((string)($result['liveness_reason'] ?? 'UNKNOWN'));
		if (!preg_match('/^[A-Z0-9_]{1,64}$/', $livenessReason)) {
			$livenessReason = 'UNKNOWN';
		}
		$challenge = null;
		if (isset($result['challenge']) && is_array($result['challenge'])) {
			$type = strtoupper((string)($result['challenge']['type'] ?? ''));
			$token = (string)($result['challenge']['token'] ?? '');
			$expiresIn = (int)($result['challenge']['expires_in'] ?? 0);
			if (in_array($type, ['LOOK_LEFT', 'LOOK_RIGHT'], true) && preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) && $expiresIn >= 1 && $expiresIn <= 60) {
				$challenge = ['type' => $type, 'token' => $token, 'expires_in' => $expiresIn];
			}
		}
		return [
			'decision' => $decision,
			'outcome' => $outcome,
				'identity_decision' => in_array($identityDecision, ['ACCEPT', 'REVIEW', 'REJECT'], true) ? $identityDecision : 'REVIEW',
			'candidate_ref' => $decision === 'ACCEPT' ? $candidateRef : null,
			'top1_score' => $result['top1_score'] ?? null,
			'top2_score' => $result['top2_score'] ?? null,
			'margin' => $result['margin'] ?? null,
			'quality' => isset($result['quality']) && is_array($result['quality']) ? $result['quality'] : [],
			'planar_spoof_flag' => !empty($result['planar_spoof_flag']),
			'liveness' => $liveness,
			'liveness_score' => $livenessScore,
			'liveness_reason' => $livenessReason,
			'challenge' => $challenge,
			'model_version' => $result['model_version'] ?? null,
			'latency_ms' => max(0, (int)($result['latency_ms'] ?? 0)),
			'decision_policy_version' => (string)($result['decision_policy_version'] ?? 'face-policy-v2'),
			'evidence_summary' => isset($result['evidence_summary']) && is_array($result['evidence_summary']) ? $result['evidence_summary'] : [],
				'consensus_count' => isset($result['consensus_count']) ? (int)$result['consensus_count'] : 0,
				'stage' => (string)($result['stage'] ?? 'MATCHING'),
				'reason_code' => (string)($result['reason_code'] ?? 'UNKNOWN'),
				'frame_geometry' => isset($result['frame_geometry']) && is_array($result['frame_geometry']) ? $result['frame_geometry'] : null,
				'selected_face_index' => isset($result['selected_face_index']) ? (int)$result['selected_face_index'] : null,
				'selection' => isset($result['selection']) && is_array($result['selection']) ? $result['selection'] : null,
		];
	}

	private function recordCaptureEvidence(?int $enrollmentAttemptId, ?int $scanAttemptId, array $evidences, int $modelVersionId, string $policyVersion, string $burstLabel = 'SCAN'): void
	{
		$check = $this->conn->query("SHOW TABLES LIKE 'face_capture_evidence'");
		if (!$check || $check->num_rows === 0) {
			return;
		}
		$contextType = $enrollmentAttemptId ? 'ENROLLMENT' : 'ATTENDANCE';
		$contextId = (int)($enrollmentAttemptId ?: ($scanAttemptId ?: 0));
		foreach ($evidences as $index => $ev) {
			if (!is_array($ev)) {
				continue;
			}
			$faceCount = (int)($ev['face_count'] ?? 1);
			$confidence = isset($ev['detector_confidence']) ? (float)$ev['detector_confidence'] : 1.0;
			$box = isset($ev['box']) && is_array($ev['box']) ? $ev['box'] : [];
			$bx = isset($box['x']) ? (float)$box['x'] : 0.0;
			$by = isset($box['y']) ? (float)$box['y'] : 0.0;
			$bw = isset($box['width']) ? (float)$box['width'] : 0.0;
			$bh = isset($box['height']) ? (float)$box['height'] : 0.0;
			$eyeDist = isset($ev['inter_eye_pixels']) ? (float)$ev['inter_eye_pixels'] : (isset($ev['inter_eye_distance']) ? (float)$ev['inter_eye_distance'] : 0.0);
			$sharpness = isset($ev['sharpness']) ? (float)$ev['sharpness'] : 0.0;
			$exposure = isset($ev['exposure']) ? (float)$ev['exposure'] : (isset($ev['brightness']) ? (float)$ev['brightness'] : 0.0);
			$contrast = isset($ev['contrast']) ? (float)$ev['contrast'] : 0.0;
			$clippedDark = isset($ev['clipped_dark_pct']) ? (float)$ev['clipped_dark_pct'] : 0.0;
			$clippedBright = isset($ev['clipped_bright_pct']) ? (float)$ev['clipped_bright_pct'] : 0.0;
			$illumination = isset($ev['illumination_balance']) ? (float)$ev['illumination_balance'] : 0.0;
			$yaw = isset($ev['pose_yaw']) ? (float)$ev['pose_yaw'] : 0.0;
			$pitch = isset($ev['pose_pitch']) ? (float)$ev['pose_pitch'] : 0.0;
			$roll = isset($ev['tilt_degrees']) ? (float)$ev['tilt_degrees'] : 0.0;
			$offset = isset($ev['centering_offset']) ? (float)$ev['centering_offset'] : 0.0;
			$padScore = isset($ev['pad_score']) && is_numeric($ev['pad_score']) ? (float)$ev['pad_score'] : "NULL";
			$padState = isset($ev['pad_state']) ? "'{$this->escape($ev['pad_state'])}'" : "NULL";
			$isAccepted = !empty($ev['accepted']) ? 1 : 0;
			$reasons = $this->escape(json_encode($ev['reasons'] ?? [], JSON_UNESCAPED_SLASHES));
			$polVersion = $this->escape($policyVersion ?: 'face-policy-v2');
			$frameHash = !empty($ev['frame_hash']) ? (string)$ev['frame_hash'] : hash('sha256', $burstLabel . '_' . $index . '_' . microtime(true));

			$this->run("INSERT INTO face_capture_evidence (context_type, context_id, frame_index, frame_hash, face_count, detector_confidence, box_x, box_y, box_w, box_h, inter_eye_pixels, sharpness, exposure, contrast, clipped_dark_pct, clipped_bright_pct, illumination_balance, yaw, pitch, roll, centering_offset, pad_score, pad_state, accepted, reasons, quality_policy_version, created_at) VALUES ('{$contextType}', '{$contextId}', '{$index}', '{$this->escape($frameHash)}', '{$faceCount}', '{$confidence}', '{$bx}', '{$by}', '{$bw}', '{$bh}', '{$eyeDist}', '{$sharpness}', '{$exposure}', '{$contrast}', '{$clippedDark}', '{$clippedBright}', '{$illumination}', '{$yaw}', '{$pitch}', '{$roll}', '{$offset}', {$padScore}, {$padState}, '{$isAccepted}', '{$reasons}', '{$polVersion}', CURRENT_TIMESTAMP)");
		}
	}

	private function attemptResponse(array $scan, string $decision, ?string $candidateRef, int $latency, bool $duplicate, $roster = null, $attendanceResult = null, $details = null, bool $memberAlreadySelected = false): array
	{
		// Read again after inference and persistence, including idempotent replies.
		// A concurrent switch-off may still race a response already in flight.
		if (!FaceSelectionGate::enabled($this->conn)) {
			return $this->rejected('SYSTEM_NOT_READY', 'Face scanning is temporarily unavailable. Continue with the attendance list');
		}
		$active = $this->one("SELECT id FROM attendance_scan WHERE id = '" . (int)$scan['id'] . "' AND status = 'ACTIVE' LIMIT 1");
		if (!$active) {
			return $this->rejected('SCAN_CANCELLED', 'Face scan was cancelled');
		}
		$outcome = is_array($details) ? (string)($details['outcome'] ?? '') : '';
		if ($outcome === '') {
			$outcome = $decision === 'ACCEPT' ? 'ACCEPT' : 'UNKNOWN';
		}
		if ($decision !== 'ACCEPT' || $outcome !== 'ACCEPT') {
			if ($outcome === 'ACCEPT') $outcome = 'UNKNOWN';
			$roster = null;
			$candidateRef = null;
			$memberAlreadySelected = false;
		}
		$liveWrite = $this->automaticAttendanceEnabled();
		$attendanceState = $liveWrite ? 'NOT_RECORDED' : 'PENDING_TEACHER_COMMIT';
		$attendanceId = 0;
		$effect = $decision === 'ACCEPT' && $roster && !$memberAlreadySelected ? 'SELECT_PRESENT' : 'NONE';
		if ($liveWrite && $decision === 'ACCEPT' && $roster && is_array($attendanceResult)) {
			$writeState = (string)($attendanceResult['state'] ?? '');
			$attendanceId = (int)($attendanceResult['attendance_id'] ?? 0);
			if ($writeState === 'RECORDED') {
				$attendanceState = 'RECORDED';
				$effect = 'ATTENDANCE_RECORDED';
				$memberAlreadySelected = false;
			} elseif ($writeState === 'ALREADY_MARKED') {
				$attendanceState = 'ALREADY_PRESENT';
				$effect = 'ALREADY_PRESENT';
				$memberAlreadySelected = true;
			} else {
				$attendanceState = 'NOT_RECORDED';
				$effect = 'NONE';
			}
		}
		$response = [
			'retryable' => false, 'retry_after_ms' => 0, 'recovery_action' => 'STOP',
			'api_version' => 'v1',
			'state' => 'RECORDED',
			'decision' => $decision,
			'outcome' => $outcome,
			'member_id' => $roster ? (int)$roster['member_id'] : null,
			'contact_id' => $roster ? (int)$roster['contact_id'] : null,
			'member_name' => $roster ? $roster['fullname'] : null,
			'attendance_state' => $attendanceState,
			'attendance_id' => $attendanceId,
			'effect' => $effect,
			'member_already_selected' => $memberAlreadySelected,
			'pad_status' => is_array($details) ? (string)($details['liveness'] ?? 'DEFERRED') : 'DEFERRED',
			'duplicate' => $duplicate,
			'scan_id' => (int)$scan['id'],
			'latency_ms' => $latency,
		] + $this->responseMode();
		if (!$duplicate && FaceAttendanceConfig::getEnv('FACE_CAPTURE_NONCE_REQUIRED') === '1') {
			$nextNonce = bin2hex(random_bytes(24));
			$nextNonceHash = hash('sha256', $nextNonce);
			$this->run("UPDATE attendance_scan SET capture_nonce_hash = '{$nextNonceHash}', capture_nonce_expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 SECOND), capture_nonce_used_at = NULL WHERE id = '" . (int)$scan['id'] . "' AND status = 'ACTIVE'");
			$response['next_capture_nonce'] = $nextNonce;
		}
		if (is_array($details)) {
			$response['top1_score'] = $details['top1_score'] ?? null;
			$response['top2_score'] = $details['top2_score'] ?? null;
			$response['margin'] = $details['margin'] ?? null;
			$response['quality'] = $details['quality'] ?? [];
			$response['planar_spoof_flag'] = !empty($details['planar_spoof_flag']);
			$response['liveness'] = $details['liveness'] ?? 'NOT_RUN';
			$response['liveness_score'] = $details['liveness_score'] ?? null;
			$response['liveness_reason'] = $details['liveness_reason'] ?? 'UNKNOWN';
			$response['challenge'] = $details['challenge'] ?? null;
			$response['model_version'] = $details['model_version'] ?? null;
			$response['decision_policy_version'] = $details['decision_policy_version'] ?? (string)($scan['calibration_policy_version'] ?: 'face-policy-v2');
			$response['evidence_summary'] = $details['evidence_summary'] ?? [];
				$response['consensus_count'] = (int)($details['consensus_count'] ?? 0);
				$response['stage'] = $details['stage'] ?? 'MATCHING';
				$response['reason_code'] = $details['reason_code'] ?? 'UNKNOWN';
				$response['frame_geometry'] = $details['frame_geometry'] ?? null;
				$response['selected_face_index'] = $details['selected_face_index'] ?? null;
				$response['selection'] = $details['selection'] ?? null;
		}
		if (is_array($attendanceResult) && !in_array(($attendanceResult['state'] ?? ''), ['RECORDED', 'ALREADY_MARKED'], true)) {
			$response['write_reason'] = $attendanceResult['reason'] ?? 'ATTENDANCE_WRITE_REJECTED';
			$response['write_message'] = $attendanceResult['message'] ?? 'Attendance was not recorded';
		}
		return $response;
	}

	private function providerMetadata(): array
	{
		$metadata = $this->inference->providerMetadata();
		foreach (['provider_id', 'provider_version', 'model_version_id', 'model_version', 'calibration_policy_version'] as $field) {
			if (!array_key_exists($field, $metadata) || $metadata[$field] === '') {
				throw new RuntimeException('Recognition provider metadata is incomplete');
			}
		}
		$metadata['model_version_id'] = (int)$metadata['model_version_id'];
		if ($metadata['model_version_id'] < 1) {
			throw new RuntimeException('Recognition provider model version is invalid');
		}
		return $metadata;
	}

	private function scanProviderMetadata(array $scan): array
	{
		return [
			'provider_id' => (string)$scan['provider_id'],
			'provider_version' => (string)$scan['provider_version'],
			'model_version_id' => (int)$scan['model_version_id'],
			'calibration_policy_version' => (string)$scan['calibration_policy_version'],
		];
	}

	private function validateProviderModel(array $provider): ?array
	{
		try {
			$modelVersionId = (int)$provider['model_version_id'];
			$row = $this->one("SELECT id, model_name, provider_id, provider_version, policy_version, status FROM face_model_version WHERE id = '{$modelVersionId}' LIMIT 1");
		} catch (Throwable $exception) {
			return [
				'reason' => 'FACE_SCHEMA_NOT_READY',
				'message' => 'Face attendance storage requires the supervised scan migration',
			];
		}
		if (!$row || (string)$row['status'] !== 'ACTIVE') {
			return [
				'reason' => 'MODEL_VERSION_INACTIVE',
				'message' => 'The configured recognition model is not active',
			];
		}
		if ((string)$row['provider_id'] !== (string)$provider['provider_id']
			|| (string)$row['provider_version'] !== (string)$provider['provider_version']
			|| (string)$row['model_name'] !== (string)$provider['model_version']
			|| (string)$row['policy_version'] !== (string)$provider['calibration_policy_version']) {
			return [
				'reason' => 'PROVIDER_MODEL_MISMATCH',
				'message' => 'Recognition provider, model, and calibration versions do not match',
			];
		}
		return null;
	}

	private function deleteSidecar(string $token): void
	{
		try {
			$this->inference->deleteScan($token);
		} catch (Throwable $ignored) {
			// Database state remains authoritative; expiry is the cleanup fallback.
		}
	}

	private function rejected(string $reason, string $message, ?bool $retryable = null, ?string $action = null): array
	{
		$retryable = $retryable ?? in_array($reason, ['INFERENCE_BUSY', 'INFERENCE_OFFLINE', 'INFERENCE_TIMEOUT', 'INFERENCE_UNAVAILABLE', 'SCAN_BUSY'], true);
		return ['retryable' => $retryable, 'retry_after_ms' => $retryable ? 1000 : 0, 'recovery_action' => $action ?? ($retryable ? 'CHECK_STATUS' : 'STOP'), 'api_version' => 'v1', 'state' => 'REJECTED', 'outcome' => $reason === 'REJECT_REPLAY' ? 'REJECT_REPLAY' : 'SYSTEM_NOT_READY', 'reason' => $reason, 'error' => 1, 'message' => $message, 'pad_status' => 'DEFERRED'] + $this->responseMode();
	}

	private function decimal($value): string
	{
		return is_numeric($value) ? (string)(float)$value : 'NULL';
	}

	private function lock(string $name): bool
	{
		$row = $this->one("SELECT GET_LOCK('{$this->escape($name)}', 0) AS acquired");
		return $row && (int)$row['acquired'] === 1;
	}

	private function unlock(string $name): void
	{
		try {
			$this->run("SELECT RELEASE_LOCK('{$this->escape($name)}')");
		} catch (Throwable $ignored) {
		}
	}

	private function one(string $sql)
	{
		$result = $this->run($sql);
		if ($result instanceof mysqli_result) {
			$row = $result->fetch_assoc();
			$result->free();
			return $row ?: null;
		}
		return null;
	}

	private function run(string $sql)
	{
		$result = $this->conn->query($sql);
		if ($result === false) {
			throw new RuntimeException($this->conn->error);
		}
		return $result;
	}

	private function escape($value): string
	{
		return $this->conn->real_escape_string((string)$value);
	}
}
