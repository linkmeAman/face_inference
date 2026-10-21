<?php

if (!class_exists('FaceErrorLogger')) {
	require_once __DIR__ . '/FaceErrorLogger.php';
}

/**
 * First-time face mapping lifecycle.
 *
 * Program participation is the product authorization used by this workflow;
 * this service does not require a guardian-consent flag. Enrollment is still
 * explicitly teacher/admin initiated, records the notice and purpose, and
 * requires confirmation that the selected member is the child being mapped.
 * Raw frames never enter this service's database writes.
 */

if (!class_exists('FaceEnrollmentException')) {
	class FaceEnrollmentException extends RuntimeException
	{
		public $reason;
		public $conflictingMemberName = '';
		public $conflictingMemberId = 0;

		public function __construct($reason, $message, $conflictingMemberName = '', $conflictingMemberId = 0)
		{
			$this->reason = $reason;
			$this->conflictingMemberName = $conflictingMemberName;
			$this->conflictingMemberId = $conflictingMemberId;
			parent::__construct($message);
		}
	}
}

if (!interface_exists('FaceEnrollmentInferenceClient')) {
	interface FaceEnrollmentInferenceClient
	{
		public function encodeEnrollment(array $frames, array $captureContexts = []): array;
	}
}

if (!class_exists('FaceEmbeddingCipher')) {
	class FaceEmbeddingCipher
	{
		private $key;
		private $keyId;

		public function __construct(string $key, string $keyId)
		{
			if ($key === '' || $keyId === '') {
				throw new InvalidArgumentException('Face embedding encryption key and key ID are required');
			}
			$this->key = strlen($key) === 32 ? $key : hash('sha256', $key, true);
			$this->keyId = $keyId;
		}

		public function keyId(): string
		{
			return $this->keyId;
		}

		public function encrypt(array $embedding): string
		{
			$json = json_encode(array_values(array_map('floatval', $embedding)), JSON_UNESCAPED_SLASHES);
			if (!is_string($json)) {
				throw new RuntimeException('Unable to serialize face embedding');
			}
			$iv = random_bytes(12);
			$tag = '';
			$ciphertext = openssl_encrypt($json, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
			if ($ciphertext === false || strlen($tag) !== 16) {
				throw new RuntimeException('Unable to encrypt face embedding');
			}
			return 'F1' . base64_encode($iv . $tag . $ciphertext);
		}

		public function decrypt(string $encoded): array
		{
			if (substr($encoded, 0, 2) !== 'F1') {
				throw new InvalidArgumentException('Unsupported face embedding format');
			}
			$payload = base64_decode(substr($encoded, 2), true);
			if (!is_string($payload) || strlen($payload) < 28) {
				throw new InvalidArgumentException('Invalid encrypted face embedding');
			}
			$iv = substr($payload, 0, 12);
			$tag = substr($payload, 12, 16);
			$ciphertext = substr($payload, 28);
			$json = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
			$embedding = is_string($json) ? json_decode($json, true) : null;
			if (!is_array($embedding) || !$embedding) {
				throw new InvalidArgumentException('Unable to decrypt face embedding');
			}
			foreach ($embedding as $value) {
				if (!is_numeric($value) || !is_finite((float)$value)) {
					throw new InvalidArgumentException('Invalid face embedding value');
				}
			}
			return array_values(array_map('floatval', $embedding));
		}

		public function encryptBytes(string $bytes): string
		{
			$iv = random_bytes(12);
			$tag = '';
			$ciphertext = openssl_encrypt($bytes, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
			if ($ciphertext === false || strlen($tag) !== 16) {
				throw new RuntimeException('Unable to encrypt portrait bytes');
			}
			return 'P1' . base64_encode($iv . $tag . $ciphertext);
		}

		public function decryptBytes(string $encoded): string
		{
			if (substr($encoded, 0, 2) !== 'P1') {
				throw new InvalidArgumentException('Unsupported portrait format');
			}
			$payload = base64_decode(substr($encoded, 2), true);
			if (!is_string($payload) || strlen($payload) < 28) {
				throw new InvalidArgumentException('Invalid encrypted portrait');
			}
			$iv = substr($payload, 0, 12);
			$tag = substr($payload, 12, 16);
			$ciphertext = substr($payload, 28);
			$bytes = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
			if (!is_string($bytes)) {
				throw new InvalidArgumentException('Unable to decrypt portrait');
			}
			return $bytes;
		}
	}
}

class FaceEnrollmentService
{
	private $conn;
	private $inference;
	private $cipher;
	private $duplicateThreshold;
	private $maxFrameBytes;

	public function __construct(mysqli $conn, FaceEnrollmentInferenceClient $inference, FaceEmbeddingCipher $cipher, ?float $duplicateThreshold = null, int $maxFrameBytes = 1000000)
	{
		$this->conn = $conn;
		$this->inference = $inference;
		$this->cipher = $cipher;
		// This value is retained only for legacy, non-guided enrollment paths.
		// Guided setup and final duplicate decisions resolve the live inference
		// policy below; never default those decisions to the old 0.75 review band.
		$configuredThreshold = $duplicateThreshold !== null ? $duplicateThreshold : 0.90;
		if ($configuredThreshold < 0.90 || $configuredThreshold > 1.0) {
			throw new InvalidArgumentException('Face enrollment duplicate threshold must be between 0.90 and one');
		}
		$this->duplicateThreshold = $configuredThreshold;
		$this->maxFrameBytes = max(1, min($maxFrameBytes, 1000000));
	}

	private $hasV2Columns = null;
	private $hasCaptureSessions = null;
	private function captureSessionsReady(): bool
	{
		if ($this->hasCaptureSessions === null) {
			$result = $this->conn->query("SHOW TABLES LIKE 'face_enrollment_capture_session'");
			$column = $this->conn->query("SHOW COLUMNS FROM face_identity_reference LIKE 'capture_session_id'");
			$templateColumn = $this->conn->query("SHOW COLUMNS FROM face_template LIKE 'capture_session_id'");
			$this->hasCaptureSessions = $result && $result->num_rows > 0 && $column && $column->num_rows > 0 && $templateColumn && $templateColumn->num_rows > 0;
		}
		return $this->hasCaptureSessions;
	}

	private function checkV2Columns(): bool
	{
		if ($this->hasV2Columns !== null) {
			return $this->hasV2Columns;
		}
		$res = $this->conn->query("SHOW COLUMNS FROM face_template LIKE 'subject_id'");
		$this->hasV2Columns = ($res && $res->num_rows > 0);
		return $this->hasV2Columns;
	}

	private function captureSession(array $command, bool $lock = false): array
	{
		$token = (string)($command['capture_session_token'] ?? '');
		if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new FaceEnrollmentException('STALE_CAPTURE_SESSION', 'Capture session is missing or invalid');
		$hash = hash('sha256', $token);
		$row = $this->one("SELECT * FROM face_enrollment_capture_session WHERE token_hash='{$hash}'" . ($lock ? ' FOR UPDATE' : ''));
		if (!$row || (int)$row['member_id'] !== (int)($command['member_id'] ?? 0)
			|| (int)$row['branch_id'] !== (int)($command['branch_id'] ?? 0)
			|| (int)$row['actor_employee_id'] !== (int)($command['actor_employee_id'] ?? 0)
			|| !hash_equals((string)$row['browser_session_hash'], hash('sha256', (string)($command['browser_session_id'] ?? '')))
			|| $row['status'] !== 'ACTIVE' || strtotime($row['expires_at']) <= time()) {
			throw new FaceEnrollmentException('STALE_CAPTURE_SESSION', 'Capture session has expired or was replaced');
		}
		return $row;
	}

	private function eraseSessionCandidates(int $sessionId): void
	{
		$this->run("UPDATE face_template SET status='REVOKED',encrypted_embedding='',revoked_at=NOW() WHERE capture_session_id='{$sessionId}' AND status='PENDING_APPROVAL'");
		$this->run("UPDATE face_identity_reference SET status='CLEARED',encrypted_portrait='',encrypted_embedding='',cleared_at=NOW() WHERE capture_session_id='{$sessionId}' AND status IN ('PENDING_ENROLLMENT','REJECTED_DUPLICATE')");
		$this->run("UPDATE face_enrollment_attempt SET decision='EXPIRED',two_burst_state='EXPIRED',completed_at=NOW() WHERE capture_session_id='{$sessionId}' AND decision='PENDING_ADMIN_REVIEW'");
	}

	public function beginEnrollment(array $command): array
	{
		$memberId = (int)($command['member_id'] ?? 0);
		$branchId = (int)($command['branch_id'] ?? 0);
		$contactId = (int)($command['contact_id'] ?? 0);
		$mode = (string)($command['mode'] ?? 'ENROLLMENT');
		if ($memberId < 1 && $contactId > 0) {
			$memberRow = $this->one("SELECT id FROM member WHERE contact_id = '{$contactId}' AND bid = '{$branchId}' AND status = '1' AND park = '0' LIMIT 1");
			if ($memberRow) {
				$memberId = (int)$memberRow['id'];
				$command['member_id'] = $memberId;
			}
		}
		$member = $this->loadMember($memberId, $branchId, false);
		if (!$member || !in_array($mode, ['ENROLLMENT','REENROLLMENT'], true)) return $this->rejected('INVALID_COMMAND', 'Valid member and enrollment mode required');
		$active = (int)$this->scalar("SELECT COUNT(*) FROM face_template WHERE member_id='{$memberId}' AND status='ACTIVE'") > 0;
		if ($mode === 'ENROLLMENT' && $active) return $this->rejected('ALREADY_ENROLLED', 'Use Set up again for an enrolled child');
		if ($mode === 'REENROLLMENT' && !$active) return $this->rejected('NOT_ENROLLED', 'This child has no active face enrollment');
		$memberLock = $this->lockName($memberId);
		$this->acquireLock($memberLock);
		try {
			$this->conn->begin_transaction();
			try {
				$active = (int)$this->scalar("SELECT COUNT(*) FROM face_template WHERE member_id='{$memberId}' AND status='ACTIVE'") > 0;
				if ($mode === 'ENROLLMENT' && $active) { $this->conn->rollback(); return $this->rejected('ALREADY_ENROLLED', 'Use Set up again for an enrolled child'); }
				if ($mode === 'REENROLLMENT' && !$active) { $this->conn->rollback(); return $this->rejected('NOT_ENROLLED', 'This child has no active face enrollment'); }
				$session = null;
				if (!empty($command['capture_session_token'])) {
					try { $session = $this->captureSession($command, true); } catch (FaceEnrollmentException $ignored) {}
				}
				if ($session && $session['mode'] === $mode) {
					$token = (string)$command['capture_session_token'];
				} else {
					$old = $this->run("SELECT id FROM face_enrollment_capture_session WHERE member_id='{$memberId}' AND status IN ('ACTIVE','PENDING_ADMIN_REVIEW') FOR UPDATE");
					while ($row = $old->fetch_assoc()) {
						$this->eraseSessionCandidates((int)$row['id']);
						$this->run("UPDATE face_enrollment_capture_session SET status='CANCELLED',cleared_at=NOW(),reference_id=NULL WHERE id='" . (int)$row['id'] . "'");
					}
					$subjectId = $this->getOrCreateSubject((int)$member['contact_id']);
					$token = bin2hex(random_bytes(32));
					$hash = hash('sha256', $token);
					$browserHash = hash('sha256', (string)($command['browser_session_id'] ?? ''));
					$actor = (int)($command['actor_employee_id'] ?? 0);
					$this->run("INSERT INTO face_enrollment_capture_session(token_hash,member_id,subject_id,branch_id,actor_employee_id,browser_session_hash,mode,created_at,expires_at) VALUES('{$hash}','{$memberId}','{$subjectId}','{$branchId}','{$actor}','{$browserHash}','{$mode}',NOW(),DATE_ADD(NOW(),INTERVAL 24 HOUR))");
					$session = $this->captureSession(array_replace($command, ['capture_session_token' => $token]), true);
				}
				$pending = $this->getSessionPendingReference((int)$session['id'], true);
				$this->conn->commit();
				return ['state'=>'READY','capture_session_token'=>$token,'mode'=>$mode,'expires_at'=>$session['expires_at'], 'has_pending_reference'=>(bool)$pending,'pending_reference'=>$pending,'has_active_enrollment'=>$active];
			} catch (Throwable $e) { $this->conn->rollback(); throw $e; }
		} finally { $this->releaseLock($memberLock); }
	}

	public function clearReference(array $command): array
	{
		$memberId = (int)($command['member_id'] ?? 0);
		$branchId = (int)($command['branch_id'] ?? 0);
		$contactId = (int)($command['contact_id'] ?? 0);
		if ($memberId < 1 && $contactId > 0) {
			$memberRow = $this->one("SELECT id FROM member WHERE contact_id = '{$contactId}' AND bid = '{$branchId}' AND status = '1' AND park = '0' LIMIT 1");
			if ($memberRow) {
				$memberId = (int)$memberRow['id'];
				$command['member_id'] = $memberId;
			}
		}
		$lock = $this->lockName($memberId);
		$this->acquireLock($lock);
		try {
			$this->conn->begin_transaction();
			try {
				$hash = hash('sha256', (string)($command['capture_session_token'] ?? ''));
				$session = $this->one("SELECT * FROM face_enrollment_capture_session WHERE token_hash='{$hash}' AND member_id='{$memberId}' FOR UPDATE");
				if (!$session) throw new FaceEnrollmentException('STALE_CAPTURE_SESSION', 'Capture session unavailable');
				if ((int)$session['branch_id'] !== (int)($command['branch_id'] ?? 0)
					|| (int)$session['actor_employee_id'] !== (int)($command['actor_employee_id'] ?? 0)
					|| !hash_equals((string)$session['browser_session_hash'], hash('sha256', (string)($command['browser_session_id'] ?? ''))))
					throw new FaceEnrollmentException('STALE_CAPTURE_SESSION', 'Capture session does not belong to this browser');
				if ($session['status'] === 'CLEARED') { $this->conn->commit(); return ['state'=>'CLEARED']; }
				if (!in_array($session['status'], ['ACTIVE','PENDING_ADMIN_REVIEW'], true) || strtotime($session['expires_at']) <= time()) throw new FaceEnrollmentException('STALE_CAPTURE_SESSION', 'Capture session expired or was replaced');
				$referenceId = (int)($command['reference_id'] ?? 0);
				if ($referenceId > 0) {
					$reference = $this->one("SELECT status FROM face_identity_reference WHERE id='{$referenceId}' FOR UPDATE");
					if ($reference && $reference['status'] === 'ACTIVE') throw new FaceEnrollmentException('PROTECTED_ACTIVE_REFERENCE', 'Active reference cannot be cleared by retake');
					if ((int)$session['reference_id'] !== $referenceId || (int)$this->scalar("SELECT COUNT(*) FROM face_template WHERE reference_id='{$referenceId}' AND status='ACTIVE'") > 0) throw new FaceEnrollmentException('PROTECTED_ACTIVE_REFERENCE', 'Reference is protected or does not belong to this session');
				}
				$this->eraseSessionCandidates((int)$session['id']);
				$this->run("UPDATE face_enrollment_capture_session SET status='CLEARED',reference_id=NULL,cleared_at=NOW() WHERE id='" . (int)$session['id'] . "'");
				$this->conn->commit();
				FaceErrorLogger::record('FACE_REFERENCE_CLEARED', ['member_id'=>$memberId,'capture_session_id'=>(int)$session['id']]);
				return ['state'=>'CLEARED'];
			} catch (Throwable $e) { $this->conn->rollback(); throw $e; }
		} catch (FaceEnrollmentException $e) { return $this->rejected($e->reason, $e->getMessage()); }
		finally { $this->releaseLock($lock); }
	}

	public function getOrCreateSubject(int $contactId): int
	{
		$contactId = (int)$contactId;
		if ($contactId < 1) {
			throw new InvalidArgumentException('Invalid contact ID for biometric subject');
		}
		$check = $this->conn->query("SHOW TABLES LIKE 'biometric_subject'");
		if (!$check || $check->num_rows === 0) {
			return 0;
		}
		$row = $this->one("SELECT id FROM biometric_subject WHERE contact_id = '{$contactId}' LIMIT 1");
		if ($row && !empty($row['id'])) {
			return (int)$row['id'];
		}
		$this->run("INSERT INTO biometric_subject (contact_id, status) VALUES ('{$contactId}', 'ACTIVE') ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP");
		$row = $this->one("SELECT id FROM biometric_subject WHERE contact_id = '{$contactId}' LIMIT 1");
		return $row ? (int)$row['id'] : 0;
	}

	public function onboardReference(array $command): array
	{
		try {
			$branchId = (int)($command['branch_id'] ?? 0);
			$actorId = (int)($command['actor_employee_id'] ?? 0);
			$memberId = (int)($command['member_id'] ?? 0);
			$contactId = (int)($command['contact_id'] ?? 0);
			if ($memberId > 0) {
				$member = $this->loadMember($memberId, $branchId, false);
				if (!$member) {
					return $this->rejected('NOT_PROGRAM_MEMBER', 'Selected child is not an active member of this program branch');
				}
				$contactId = (int)$member['contact_id'];
			} elseif ($contactId > 0) {
				$member = $this->one("SELECT id, contact_id, status, park, bid FROM member WHERE contact_id = '{$contactId}' AND bid = '{$branchId}' AND status = '1' AND park = '0' LIMIT 1");
				if (!$member) {
					return $this->rejected('NOT_PROGRAM_MEMBER', 'Selected child is not an active member of this program branch');
				}
				$memberId = (int)$member['id'];
			} else {
				return $this->rejected('INVALID_COMMAND', 'Member or contact identifier is required');
			}
			$session = $this->captureSessionsReady() ? $this->captureSession($command) : null;

			$portraitInput = null;
			if (!empty($command['portraits']) && is_array($command['portraits'])) {
				$portraitInput = [];
				foreach ($command['portraits'] as $f) {
					if (!is_string($f) || $f === '' || strlen($f) > $this->maxFrameBytes) {
						return $this->rejected('INVALID_FRAME', 'Reference portrait burst contains an invalid frame');
					}
					$portraitInput[] = $f;
				}
				if (empty($portraitInput)) {
					return $this->rejected('INVALID_FRAME', 'Reference portrait burst is invalid or frames too large');
				}
			} elseif (!empty($command['portrait']) && is_string($command['portrait']) && strlen($command['portrait']) <= $this->maxFrameBytes) {
				$portraitInput = $command['portrait'];
			} else {
				return $this->rejected('INVALID_FRAME', 'Reference portrait is invalid or too large');
			}

			try {
				if (method_exists($this->inference, 'encodeReference')) {
					$inputToSend = $portraitInput;
					if (is_array($inputToSend)) {
						try {
							$refMethod = new ReflectionMethod($this->inference, 'encodeReference');
							$firstParam = $refMethod->getParameters()[0] ?? null;
							$pType = $firstParam ? $firstParam->getType() : null;
							if ($pType instanceof ReflectionNamedType && $pType->getName() === 'string') {
								$inputToSend = $portraitInput[0] ?? '';
							}
						} catch (Throwable $e) {}
					}
					$referenceContexts = $command['capture_contexts'] ?? ($command['capture_context'] ?? null);
					$refMethod = new ReflectionMethod($this->inference, 'encodeReference');
					$encoded = ($referenceContexts && $refMethod->getNumberOfParameters() >= 2)
						? $this->inference->encodeReference($inputToSend, $referenceContexts)
						: $this->inference->encodeReference($inputToSend);
				} else {
					return $this->rejected('INFERENCE_NOT_READY', 'Reference encoding is not supported by inference provider');
				}
			} catch (FaceEnrollmentException $exception) {
				FaceErrorLogger::record('REFERENCE_INFERENCE_FAILED', [
					'member_id' => $memberId,
					'contact_id' => $contactId,
					'reason' => $exception->reason,
					'message' => $exception->getMessage(),
				]);
				return $this->rejected($exception->reason, $exception->getMessage());
			} catch (Throwable $exception) {
				FaceErrorLogger::record('REFERENCE_INFERENCE_FAILED', [
					'member_id' => $memberId,
					'contact_id' => $contactId,
					'reason' => 'INFERENCE_FAILED',
				]);
				return $this->rejected('INFERENCE_FAILED', 'Reference portrait encoding failed');
			}

			// Indexes refer to the exact input sent to the provider. A legacy
			// string-only provider encodes the first frame and is unambiguous.
			$encodedFrames = is_array($inputToSend) ? array_values($inputToSend) : [$inputToSend];
			$selectedFrameIndex = array_key_exists('selected_frame_index', $encoded)
				? $encoded['selected_frame_index'] : (count($encodedFrames) === 1 ? 0 : null);
			if (!is_int($selectedFrameIndex) || $selectedFrameIndex < 0 || $selectedFrameIndex >= count($encodedFrames)) {
				return $this->rejected('INVALID_INFERENCE_RESULT', 'Reference frame selection is missing or invalid');
			}
			$portraitToStore = $encodedFrames[$selectedFrameIndex];
			$quality = $encoded['quality'] ?? $encoded['quality_evidence'] ?? [];
			unset($quality['embedding'], $quality['embeddings']);
			if (!isset($quality['pose_ok'])) {
				$quality['pose_ok'] = !empty($quality['facing_camera']) || (!empty($quality['yaw_ok']) && !empty($quality['tilt_ok']));
			}
			$pad = $encoded['pad'] ?? [
				'state' => (isset($encoded['pad_score']) && $encoded['pad_score'] > 0.5) ? 'LIVE' : 'UNKNOWN',
				'score' => $encoded['pad_score'] ?? null,
			];
			if (empty($encoded['embedding']) || !is_array($encoded['embedding']) || count($encoded['embedding']) !== 128) {
				return $this->rejected('INVALID_INFERENCE_RESULT', 'Reference embedding is incomplete or invalid dimension');
			}
			if (empty($quality['sharpness_ok']) || empty($quality['exposure_ok']) || empty($quality['pose_ok']) || empty($quality['size_ok']) || empty($quality['centred_ok'])) {
				$reasons = $quality['reasons'] ?? ['QUALITY_FAILED'];
				FaceErrorLogger::record('REFERENCE_VALIDATION_REJECTED', [
					'member_id' => $memberId,
					'contact_id' => $contactId,
					'stage' => 'QUALITY',
					'reason' => 'QUALITY_FAILED',
					'quality_reasons' => (array)$reasons,
				]);
				return $this->rejected('QUALITY_FAILED', 'Reference portrait does not meet quality requirements: ' . implode(', ', (array)$reasons), ['quality_evidence' => $quality]);
			}

			// A reference preview is only one short capture. Its duplicate score is
			// advisory; the independent 5+3 enrollment bursts make the binding
			// identity decision.
			$modelVersionId = (int)($encoded['model_version_id'] ?? 1);
			$policyVersion = (string)($encoded['calibration_policy_version'] ?? 'face-policy-v2');
			$existingSubject = $this->one("SELECT id FROM biometric_subject WHERE contact_id = '{$contactId}' LIMIT 1");
			$subjectId = $existingSubject ? (int)$existingSubject['id'] : 0;
			$dup = $this->checkEmbeddingDuplicate($encoded['embedding'], $subjectId, $modelVersionId, $contactId, $memberId);
			if ($dup['state'] === 'UNAVAILABLE') {
				return $this->rejected('IDENTITY_INDEX_UNAVAILABLE', 'Face identity template index is unavailable or corrupted');
			}
			$possibleDuplicate = $dup['state'] === 'CONFLICT' ? $dup : null;
			$uncalibratedTesting = !empty($command['allow_uncalibrated_testing']);
			if (!$uncalibratedTesting) {
				if (($pad['state'] ?? '') === 'UNKNOWN') {
					FaceErrorLogger::record('REFERENCE_VALIDATION_REJECTED', [
						'member_id' => $memberId,
						'contact_id' => $contactId,
						'stage' => 'PAD',
						'reason' => 'PAD_CALIBRATION_REQUIRED',
						'pad_state' => 'UNKNOWN',
						'pad_score' => $pad['score'] ?? null,
					]);
					return $this->rejected('PAD_CALIBRATION_REQUIRED', 'Liveness calibration is not approved for the current preprocessing version', ['pad' => $pad]);
				}
				if (($pad['state'] ?? '') !== 'LIVE') {
					FaceErrorLogger::record('REFERENCE_VALIDATION_REJECTED', [
						'member_id' => $memberId,
						'contact_id' => $contactId,
						'stage' => 'PAD',
						'reason' => 'PAD_REJECTED',
						'pad_state' => $pad['state'] ?? 'NOT_RUN',
						'pad_score' => $pad['score'] ?? null,
					]);
					return $this->rejected('PAD_REJECTED', 'Reference portrait failed passive liveness detection', ['pad' => $pad]);
				}
			} else {
				$quality['enrollment_mode'] = 'UNCALIBRATED_TEST';
				FaceErrorLogger::record('REFERENCE_UNCALIBRATED_TEST_ONBOARDED', [
					'member_id' => $memberId,
					'contact_id' => $contactId,
					'pad_state' => $pad['state'] ?? 'NOT_RUN',
					'pad_score' => $pad['score'] ?? null,
				]);
			}

			if ($subjectId < 1) {
				$subjectId = $this->getOrCreateSubject($contactId);
			}

			$memberLock = $this->lockName($memberId);
			$lockName = 'face_enroll_tenant_' . $modelVersionId;
			$this->acquireLock($memberLock);
			$this->acquireLock($lockName);
			$this->conn->begin_transaction();
			try {
				if ($session) $session = $this->captureSession($command, true);
				if (!$this->loadMember($memberId, $branchId, true)) throw new FaceEnrollmentException('NOT_PROGRAM_MEMBER', 'Selected child is no longer active in this branch');
				$this->run("UPDATE face_identity_reference SET status = '" . ($session ? 'CLEARED' : 'SUPERSEDED') . "'" . ($session ? ",encrypted_portrait='',encrypted_embedding='',cleared_at=NOW()" : '') . " WHERE subject_id = '{$subjectId}' AND status = 'PENDING_ENROLLMENT'" . ($session ? " AND capture_session_id='" . (int)$session['id'] . "'" : ''));
				$encPortrait = $this->cipher->encryptBytes($portraitToStore);
				$encEmbedding = $this->cipher->encrypt($encoded['embedding']);
				$portraitHash = hash('sha256', $portraitToStore);
				$qualityJson = $this->escape(json_encode($quality, JSON_UNESCAPED_SLASHES));

				$captureColumn = $session ? ',capture_session_id' : '';
				$captureValue = $session ? ",'" . (int)$session['id'] . "'" : '';
				$this->run("INSERT INTO face_identity_reference (subject_id, encrypted_portrait, encrypted_embedding, encryption_key_id, embedding_dimension, source, quality_evidence, model_version_id, policy_version, verifier_actor_id, verified_at, status{$captureColumn}) VALUES ('{$subjectId}', '{$this->escape($encPortrait)}', '{$this->escape($encEmbedding)}', '{$this->escape($this->cipher->keyId())}', 128, 'STAFF_ONBOARDING', '{$qualityJson}', '{$modelVersionId}', '{$this->escape($policyVersion)}', '{$actorId}', CURRENT_TIMESTAMP, 'PENDING_ENROLLMENT'{$captureValue})");
				$referenceId = (int)$this->conn->insert_id;
				if ($session) $this->run("UPDATE face_enrollment_capture_session SET reference_id='{$referenceId}' WHERE id='" . (int)$session['id'] . "'");
				$drift = null;
				if ($session && $session['mode'] === 'REENROLLMENT') {
					$activeReference = $this->getActiveReference($contactId);
					if ($activeReference && (int)$activeReference['model_version_id'] === $modelVersionId) $drift = $this->cosine($activeReference['embedding'], $encoded['embedding']);
				}
				$this->conn->commit();

				return [
					'state' => 'ACTIVE',
					'reference_id' => $referenceId,
					'selected_frame_index' => $selectedFrameIndex,
					'subject_id' => $subjectId,
					'contact_id' => $contactId,
					'member_id' => $memberId,
					'quality_evidence' => $quality,
					'policy_version' => $policyVersion,
					'pad_state' => $pad['state'] ?? 'LIVE',
					'reference_status' => 'PENDING_ENROLLMENT',
					'possible_duplicate' => $possibleDuplicate,
					'identity_drift_score' => $drift,
					'early_drift_warning' => $session && $session['mode'] === 'REENROLLMENT' && ($drift === null || $drift < 0.90),
					'identity_drift_unavailable' => $session && $session['mode'] === 'REENROLLMENT' && $drift === null,
				];
			} catch (Throwable $e) {
				$this->conn->rollback();
				throw $e;
			} finally {
				$this->releaseLock($lockName);
				$this->releaseLock($memberLock);
			}
		} catch (FaceEnrollmentException $e) {
			return $this->rejected($e->reason, $e->getMessage());
		}
	}

	public function getSessionPendingReference(int $sessionId, bool $includePortrait = false, bool $includeEmbedding = false): ?array
	{
		$row = $this->one("SELECT fir.*,bs.contact_id,TRIM(CONCAT(c.fname,' ',c.lname)) fullname FROM face_identity_reference fir JOIN biometric_subject bs ON bs.id=fir.subject_id JOIN contact c ON c.id=bs.contact_id WHERE fir.capture_session_id='{$sessionId}' AND fir.status='PENDING_ENROLLMENT' ORDER BY fir.id DESC LIMIT 1");
		return $row ? $this->referenceDto($row, $includePortrait, $includeEmbedding) : null;
	}

	private function getLegacyPendingReference(int $contactId): ?array
	{
		$row = $this->one("SELECT fir.*,bs.contact_id,TRIM(CONCAT(c.fname,' ',c.lname)) fullname FROM face_identity_reference fir JOIN biometric_subject bs ON bs.id=fir.subject_id JOIN contact c ON c.id=bs.contact_id WHERE bs.contact_id='{$contactId}' AND fir.status='PENDING_ENROLLMENT' ORDER BY fir.id DESC LIMIT 1");
		return $row ? $this->referenceDto($row, false, true) : null;
	}

	public function getActiveReference(int $contactId, bool $includePortrait = false, bool $includeEmbedding = true): ?array
	{
		$contactId = (int)$contactId;
		if ($contactId < 1) {
			return null;
		}
		$check = $this->conn->query("SHOW TABLES LIKE 'face_identity_reference'");
		if (!$check || $check->num_rows === 0) {
			return null;
		}
		$row = $this->one("SELECT fir.*, fir.verifier_actor_id AS verified_by_actor_id, bs.contact_id, TRIM(CONCAT(c.fname, ' ', c.lname)) AS fullname FROM biometric_subject bs JOIN face_identity_reference fir ON fir.subject_id = bs.id JOIN contact c ON c.id = bs.contact_id WHERE bs.contact_id = '{$contactId}' AND fir.status = 'ACTIVE' ORDER BY fir.id DESC LIMIT 1");
		if (!$row) {
			return null;
		}
		return $this->referenceDto($row, $includePortrait, $includeEmbedding);
	}

	private function referenceDto(array $row, bool $includePortrait, bool $includeEmbedding): ?array
	{
		$embedding = [];
		if ($includeEmbedding) {
			try { $embedding = $this->cipher->decrypt((string)$row['encrypted_embedding']); }
			catch (Throwable $e) { return null; }
		}
		$portraitDataUrl = null;
		if ($includePortrait && !empty($row['encrypted_portrait'])) {
			try {
				$rawBytes = $this->cipher->decryptBytes((string)$row['encrypted_portrait']);
				$portraitDataUrl = 'data:image/jpeg;base64,' . base64_encode($rawBytes);
				if (strlen($portraitDataUrl) > 60000) $portraitDataUrl = null;
			} catch (Throwable $e) {
				$portraitDataUrl = null;
			}
		}
		$dto = [
			'id' => (int)$row['id'],
			'subject_id' => (int)$row['subject_id'],
			'contact_id' => (int)$row['contact_id'],
			'fullname' => (string)$row['fullname'],
			'model_version_id' => (int)$row['model_version_id'],
			'preprocessing_version' => (string)($row['preprocessing_version'] ?? ''),
			'portrait_data_url' => $portraitDataUrl,
			'policy_version' => (string)$row['policy_version'],
			'verified_at' => (string)$row['verified_at'],
			'verifier_actor_id' => (int)($row['verifier_actor_id'] ?? $row['verified_by_actor_id'] ?? 0),
			'verified_by_actor_id' => (int)($row['verifier_actor_id'] ?? $row['verified_by_actor_id'] ?? 0),
			'status' => (string)$row['status'],
		];
		if ($includeEmbedding) $dto['embedding'] = $embedding;
		return $dto;
	}

	public function checkEmbeddingDuplicate(array $embedding, int $subjectId, int $modelVersionId, int $contactId = 0, int $memberId = 0): array
	{
		$dimension = count($embedding);
		$res = $this->conn->query(
			"SELECT ft.id, ft.subject_id, ft.member_id, ft.encrypted_embedding, ft.embedding_dimension, " .
			"COALESCE(bs.contact_id, m.contact_id) AS contact_id, TRIM(CONCAT(c.fname, ' ', c.lname)) AS fullname " .
			"FROM face_template ft " .
			"LEFT JOIN biometric_subject bs ON bs.id = ft.subject_id " .
			"LEFT JOIN member m ON m.id = ft.member_id " .
			"LEFT JOIN contact c ON c.id = COALESCE(bs.contact_id, m.contact_id) " .
			"WHERE ft.status = 'ACTIVE' " .
			"AND ft.model_version_id = '{$modelVersionId}' " .
			($subjectId > 0 ? "AND (ft.subject_id IS NULL OR ft.subject_id <> '{$subjectId}') " : "") .
			($contactId > 0 ? "AND (COALESCE(bs.contact_id, m.contact_id) IS NULL OR COALESCE(bs.contact_id, m.contact_id) <> '{$contactId}') " : "") .
			($memberId > 0 ? "AND ft.member_id <> '{$memberId}' " : "") .
			"ORDER BY ft.id"
		);
		if (!$res) {
			return ['state' => 'UNAVAILABLE'];
		}
		$maxScore = -1.0;
		$conflict = null;
		while ($row = $res->fetch_assoc()) {
			if ((int)$row['embedding_dimension'] !== $dimension) {
				return ['state' => 'UNAVAILABLE'];
			}
			try {
				$existingEmb = $this->cipher->decrypt((string)$row['encrypted_embedding']);
			} catch (Throwable $e) {
				return ['state' => 'UNAVAILABLE'];
			}
			if (count($existingEmb) !== $dimension) {
				return ['state' => 'UNAVAILABLE'];
			}
			$score = $this->cosine($embedding, $existingEmb);
			if ($score > $maxScore) {
				$maxScore = $score;
				$conflict = [
					'conflicting_member_id' => (int)$row['member_id'],
					'conflicting_contact_id' => (int)$row['contact_id'],
					'conflicting_member_name' => !empty($row['fullname']) ? $row['fullname'] : "Student #{$row['member_id']}",
					'score' => round($score, 4),
				];
			}
		}

		// Also check active references in face_identity_reference
		$refCheck = $this->conn->query("SHOW TABLES LIKE 'face_identity_reference'");
		if ($refCheck && $refCheck->num_rows > 0) {
			$refRes = $this->conn->query(
				"SELECT fir.id, fir.subject_id, fir.encrypted_embedding, fir.embedding_dimension, " .
				"bs.contact_id, TRIM(CONCAT(c.fname, ' ', c.lname)) AS fullname " .
				"FROM face_identity_reference fir " .
				"JOIN biometric_subject bs ON bs.id = fir.subject_id " .
				"JOIN contact c ON c.id = bs.contact_id " .
				"WHERE fir.status = 'ACTIVE' " .
				"AND fir.model_version_id = '{$modelVersionId}' " .
				($subjectId > 0 ? "AND fir.subject_id <> '{$subjectId}' " : "") .
				($contactId > 0 ? "AND bs.contact_id <> '{$contactId}' " : "") .
				"ORDER BY fir.id"
			);
			if ($refRes) {
				while ($row = $refRes->fetch_assoc()) {
					if ((int)$row['embedding_dimension'] !== $dimension) {
						return ['state' => 'UNAVAILABLE'];
					}
					try {
						$existingEmb = $this->cipher->decrypt((string)$row['encrypted_embedding']);
					} catch (Throwable $e) {
						return ['state' => 'UNAVAILABLE'];
					}
					if (count($existingEmb) !== $dimension) {
						return ['state' => 'UNAVAILABLE'];
					}
					$score = $this->cosine($embedding, $existingEmb);
					if ($score > $maxScore) {
						$maxScore = $score;
						$conflict = [
							'conflicting_member_id' => 0,
							'conflicting_contact_id' => (int)$row['contact_id'],
							'conflicting_member_name' => !empty($row['fullname']) ? $row['fullname'] : "Student (Contact #{$row['contact_id']})",
							'score' => round($score, 4),
						];
					}
				}
			}
		}

		if ($maxScore >= 0.90 && $conflict) {
			return array_merge(['state' => 'CONFLICT'], $conflict);
		}
		return ['state' => 'CLEAR', 'score' => max(0.0, round($maxScore, 4))];
	}

	public function enroll(array $command): array
	{
		try {
			$command = $this->normalizeCommand($command);
			$member = $this->loadMember($command['member_id'], $command['branch_id'], false);
			if (!$member) {
				return $this->rejected('NOT_PROGRAM_MEMBER', 'Selected child is not an active member of this program branch');
			}
			$session = $this->captureSessionsReady() ? $this->captureSession($command) : null;
			$activeTemplates = (int)$this->scalar("SELECT COUNT(*) FROM face_template WHERE member_id = '{$command['member_id']}' AND status = 'ACTIVE'");
			if ($activeTemplates > 0 && (!$session || $session['mode'] !== 'REENROLLMENT')) {
				return [
					'state' => 'ALREADY_ENROLLED',
					'member_id' => $command['member_id'],
					'template_count' => $activeTemplates,
				];
			}

			$isProtocol2 = !empty($command['burst_a']) && !empty($command['burst_b']) || ($command['protocol_version'] ?? 1) == 2;
			$contactId = (int)$member['contact_id'];
			$reference = $session ? $this->getSessionPendingReference((int)$session['id'], false, true) : ($this->getLegacyPendingReference($contactId) ?: $this->getActiveReference($contactId, false));

			if ($isProtocol2 && !$reference) {
				return $this->rejected('REFERENCE_REQUIRED', 'Face setup is unavailable until the selected child has an active verified reference');
			}

			if ($isProtocol2 && !empty($command['reference_id']) && (int)$command['reference_id'] !== (int)$reference['id']) {
				return $this->rejected('REFERENCE_MISMATCH', 'Active verified reference has changed; please restart face setup');
			}

			// If reference exists, prefer Protocol 2 two-burst execution
			if ($reference && !$isProtocol2 && !empty($command['frames']) && count($command['frames']) >= 8) {
				$command['burst_a'] = array_slice($command['frames'], 0, 5);
				$command['burst_b'] = array_slice($command['frames'], 5, 3);
				$isProtocol2 = true;
			}

			if ($isProtocol2) {
				return $this->enrollV2($command, $member, $reference);
			}

			// Legacy single-burst fallback
			try {
				$contexts = isset($command['capture_contexts']) && is_array($command['capture_contexts']) ? $command['capture_contexts'] : [];
				$method = new ReflectionMethod($this->inference, 'encodeEnrollment');
				$encoded = ($contexts && $method->getNumberOfParameters() >= 2)
					? $this->inference->encodeEnrollment($command['frames'], $contexts)
					: $this->inference->encodeEnrollment($command['frames']);
			} catch (FaceEnrollmentException $exception) {
				FaceErrorLogger::record('ENROLLMENT_INFERENCE_FAILED', [
					'member_id' => $command['member_id'],
					'branch_id' => $command['branch_id'],
					'actor_employee_id' => $command['actor_employee_id'],
					'reason' => $exception->reason,
					'message' => $exception->getMessage(),
				]);
				return $this->rejected($exception->reason, $exception->getMessage());
			} catch (Throwable $exception) {
				FaceErrorLogger::record('ENROLLMENT_INFERENCE_FAILED', [
					'member_id' => $command['member_id'],
					'branch_id' => $command['branch_id'],
					'actor_employee_id' => $command['actor_employee_id'],
					'reason' => 'INFERENCE_FAILED',
				]);
				return $this->rejected('INFERENCE_FAILED', 'Face enrollment could not be completed');
			}
			$prepared = $this->validateInferenceResult($encoded, !empty($command['allow_uncalibrated_testing']));

			$lockName = $this->identityLockName($command['branch_id'], $prepared['model_version_id']);
			$transactionStarted = false;
			try {
				$this->acquireLock($lockName);
				$this->conn->begin_transaction();
				$transactionStarted = true;

				$member = $this->loadMember($command['member_id'], $command['branch_id'], true);
				if (!$member) {
					throw new FaceEnrollmentException('NOT_PROGRAM_MEMBER', 'Selected child is not an active member of this program branch');
				}
				$activeTemplates = (int)$this->scalar("SELECT COUNT(*) FROM face_template WHERE member_id = '{$command['member_id']}' AND status = 'ACTIVE'");
				if ($activeTemplates > 0) {
					$this->conn->commit();
					$transactionStarted = false;
					return [
						'state' => 'ALREADY_ENROLLED',
						'member_id' => $command['member_id'],
						'template_count' => $activeTemplates,
					];
				}

				$duplicatePolicy = $this->activeDuplicatePolicy((string)$prepared['calibration_policy_version'], (int)$prepared['model_version_id']);
				if (is_callable([$this->inference, 'identityPolicy']) && $duplicatePolicy === null) {
					throw new FaceEnrollmentException('CHECK_UNAVAILABLE', 'The active identity policy is unavailable or does not match this enrollment');
				}
				$collision = $this->findDuplicateIdentity($command, $prepared,
					$duplicatePolicy !== null ? (float)$duplicatePolicy['duplicate_block_threshold'] : null);
				if ($collision !== null) {
					$conflictingMemberId = (int)$collision['member_id'];
					$confNameRes = $this->conn->query("SELECT TRIM(CONCAT(c.fname, ' ', c.lname)) AS fullname FROM member m JOIN contact c ON m.contact_id = c.id WHERE m.id = '{$conflictingMemberId}' LIMIT 1");
					$confNameRow = $confNameRes ? $confNameRes->fetch_assoc() : null;
					$conflictingName = !empty($confNameRow['fullname']) ? $confNameRow['fullname'] : "Student #{$conflictingMemberId}";

					FaceErrorLogger::record('FACE_ENROLLMENT_DUPLICATE_IDENTITY', [
						'member_id' => $command['member_id'],
						'conflicting_member_id' => $conflictingMemberId,
						'conflicting_member_name' => $conflictingName,
						'branch_id' => $command['branch_id'],
						'model_version_id' => $prepared['model_version_id'],
						'collision_score' => round($collision['score'], 7),
					]);
					throw new FaceEnrollmentException(
						'FACE_ALREADY_ASSIGNED',
						"This face is already registered for {$conflictingName}. Each child must have their own unique face registered.",
						$conflictingName,
						$conflictingMemberId
					);
				}

				$subjectId = $this->getOrCreateSubject((int)$member['contact_id']);
				$this->insertAuthorization($command);
				$templateIds = [];
				foreach ($prepared['embeddings'] as $embedding) {
					$templateIds[] = $this->insertTemplate($command, $prepared, $embedding, $subjectId);
				}
				$this->conn->commit();
				$transactionStarted = false;

			return [
				'state' => !empty($prepared['testing_mode']) ? 'VALIDATION_ONLY' : 'ACTIVE',
				'outcome' => 'CLEAR_NEW_PERSON',
				'testing_mode' => !empty($prepared['testing_mode']),
					'member_id' => $command['member_id'],
					'contact_id' => (int)$member['contact_id'],
					'subject_id' => $subjectId,
					'template_ids' => $templateIds,
					'template_count' => count($templateIds),
					'model_version_id' => $prepared['model_version_id'],
					'quality_summary' => $prepared['quality_summary'],
				];
			} catch (FaceEnrollmentException $exception) {
				if ($transactionStarted) {
					$this->conn->rollback();
				}
				$extra = [];
				if (!empty($exception->conflictingMemberName)) {
					$extra['conflicting_member_name'] = $exception->conflictingMemberName;
					$extra['conflicting_member_id'] = $exception->conflictingMemberId;
				}
				return $this->rejected($exception->reason, $exception->getMessage(), $extra);
			} catch (Throwable $exception) {
				if ($transactionStarted) {
					$this->conn->rollback();
				}
				throw $exception;
			} finally {
				$this->releaseLock($lockName);
			}
		} catch (FaceEnrollmentException $exception) {
			$extra = [];
			if (!empty($exception->conflictingMemberName)) {
				$extra['conflicting_member_name'] = $exception->conflictingMemberName;
				$extra['conflicting_member_id'] = $exception->conflictingMemberId;
			}
			return $this->rejected($exception->reason, $exception->getMessage(), $extra);
		}
	}

	public const GUIDED_POSE_PROTOCOL = 'guided-five-v1';
	public const GUIDED_SELECTION_PROTOCOL = 'guided-five-select-v1';
	public const GUIDED_ADAPTIVE_PROTOCOL = 'guided-adaptive-v2';
	public const GUIDED_TEMPLATE_COUNT = 6;
	public const GUIDED_SELECTION_BURST_A_COUNT = 10;
	private const GUIDED_SELECTION_MAX_TOTAL_BYTES = 10000000;

	private static function supportsGuidedEnrollment($provider): bool
	{
		return is_callable([$provider, 'encodeEnrollmentV2'])
			&& (new ReflectionMethod($provider, 'encodeEnrollmentV2'))->getNumberOfParameters() >= 6;
	}

	/** Only forward Python's targets when this PHP provider can send the protocol. */
	public static function guidedEnrollmentCapability($provider, $capability): ?array
	{
		$protocol = $capability['pose_protocol'] ?? null;
		if (!self::supportsGuidedEnrollment($provider) || !is_array($capability)
			|| !in_array($protocol, [self::GUIDED_POSE_PROTOCOL, self::GUIDED_SELECTION_PROTOCOL, self::GUIDED_ADAPTIVE_PROTOCOL], true)
			|| ($capability['template_count'] ?? null) !== self::GUIDED_TEMPLATE_COUNT
			|| !is_array($capability['windows'] ?? null)) return null;
		if ($protocol === self::GUIDED_SELECTION_PROTOCOL
			&& (!is_callable([$provider, 'supportsEnrollmentProtocol'])
				|| !$provider->supportsEnrollmentProtocol($protocol)
				|| ($capability['burst_a_count'] ?? null) !== self::GUIDED_SELECTION_BURST_A_COUNT
				|| ($capability['burst_b_count'] ?? null) !== 3
				|| ($capability['samples_per_pose'] ?? null) !== 2)) return null;
		if ($protocol === self::GUIDED_ADAPTIVE_PROTOCOL
			&& (!is_callable([$provider, 'supportsEnrollmentProtocol'])
				|| !$provider->supportsEnrollmentProtocol($protocol)
				|| ($capability['burst_a_count'] ?? null) !== 5
				|| ($capability['burst_b_count'] ?? null) !== 3
				|| ($capability['departure_ms'] ?? null) !== 750)) return null;
		$windows = $capability['windows'];
		foreach (['centre_yaw_max', 'centre_pitch_min', 'centre_pitch_max', 'side_yaw_min',
			'side_yaw_max', 'pitch_tolerance', 'pitch_delta_min', 'pitch_delta_max', 'stable_ms'] as $key) {
			$value = $windows[$key] ?? null;
			if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value <= 0) return null;
		}
		if ($windows['centre_pitch_min'] >= $windows['centre_pitch_max']
			|| $windows['centre_yaw_max'] >= $windows['side_yaw_min']
			|| $windows['side_yaw_min'] >= $windows['side_yaw_max']
			|| $windows['pitch_delta_min'] >= $windows['pitch_delta_max']) return null;
		return $capability;
	}

	private function validateGuidedResult(array $result): void
	{
		if (($result['pose_protocol'] ?? null) === self::GUIDED_ADAPTIVE_PROTOCOL) {
			$this->validateGuidedAdaptiveResult($result);
			return;
		}
		if (($result['pose_protocol'] ?? null) === self::GUIDED_SELECTION_PROTOCOL) {
			$this->validateGuidedSelectionResult($result);
			return;
		}
		$embeddings = $result['embeddings'] ?? [];
		$a = $result['burst_a_embeddings'] ?? [];
		$b = $result['burst_b_embeddings'] ?? [];
		$index = $result['burst_b_template_index'] ?? null;
		$poses = $result['quality_summary']['accepted_poses'] ?? [];
		if (($result['pose_protocol'] ?? null) !== self::GUIDED_POSE_PROTOCOL
			|| !is_array($embeddings) || count($embeddings) !== self::GUIDED_TEMPLATE_COUNT
			|| !is_array($a) || count($a) !== 5 || !is_array($b) || count($b) !== 3
			|| !is_int($index) || $index < 0 || $index >= count($b)
			|| array_slice($embeddings, 0, 5) !== $a || $embeddings[5] !== $b[$index]
			|| ($result['template_sources'] ?? []) !== ['BURST_A','BURST_A','BURST_A','BURST_A','BURST_A','BURST_B']
			|| !is_array($poses) || count($poses) !== 8) {
			throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Guided enrollment provenance is incomplete');
		}
		foreach (['centre','left','right','up','down','centre','centre','centre'] as $i => $pose) {
			$measurement = $poses[$i] ?? [];
			if (($measurement['pose'] ?? null) !== $pose
				|| ($measurement['burst'] ?? null) !== ($i < 5 ? 'BURST_A' : 'BURST_B')
				|| ($measurement['index'] ?? null) !== ($i < 5 ? $i : $i - 5)) {
				throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Guided pose order is incomplete');
			}
			foreach (['yaw', 'pitch'] as $key) {
				$value = $measurement[$key] ?? null;
				if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value)) {
					throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Guided pose measurement is missing');
				}
			}
		}
	}

	private function validateGuidedAdaptiveResult(array $result): void
	{
		$embeddings = $result['embeddings'] ?? null;
		$a = $result['burst_a_embeddings'] ?? null;
		$b = $result['burst_b_embeddings'] ?? null;
		$index = $result['burst_b_template_index'] ?? null;
		$poses = $result['quality_summary']['accepted_poses'] ?? null;
		if (!is_array($embeddings) || count($embeddings) !== 6 || !is_array($a) || count($a) !== 5
			|| !is_array($b) || count($b) !== 3 || !is_int($index) || $index < 0 || $index > 2
			|| array_slice($embeddings, 0, 5) !== $a || $embeddings[5] !== $b[$index]
			|| ($result['template_sources'] ?? null) !== ['BURST_A','BURST_A','BURST_A','BURST_A','BURST_A','BURST_B']
			|| !is_array($poses) || count($poses) !== 8) {
			throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Adaptive enrollment provenance is incomplete');
		}
		foreach ($poses as $i => $pose) {
			$expected = $i < 2 || $i >= 5 ? 'centre' : ($i === 2 ? 'left' : ($i === 3 ? 'right' : null));
			if (($pose['burst'] ?? null) !== ($i < 5 ? 'BURST_A' : 'BURST_B')
				|| ($pose['index'] ?? null) !== ($i < 5 ? $i : $i - 5)
				|| ($expected === null ? !in_array($pose['pose'] ?? null, ['left','right'], true) : ($pose['pose'] ?? null) !== $expected)) {
				throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Adaptive pose order is invalid');
			}
			foreach (['yaw','pitch'] as $field) {
				$value = $pose[$field] ?? null;
				if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value))
					throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Adaptive pose measurement is missing');
			}
		}
	}

	private function validateGuidedSelectionResult(array $result): void
	{
		$embeddings = $result['embeddings'] ?? null;
		$a = $result['burst_a_embeddings'] ?? null;
		$b = $result['burst_b_embeddings'] ?? null;
		$selected = $result['selected_burst_a_indices'] ?? null;
		$bIndex = $result['burst_b_template_index'] ?? null;
		$poses = $result['quality_summary']['accepted_poses'] ?? null;
		$expectedPoses = ['centre','left','right','up','down'];
		if (!is_array($embeddings) || count($embeddings) !== self::GUIDED_TEMPLATE_COUNT
			|| !is_array($a) || count($a) !== self::GUIDED_SELECTION_BURST_A_COUNT
			|| !is_array($b) || count($b) !== 3 || !is_array($selected) || count($selected) !== 5
			|| !is_int($bIndex) || $bIndex < 0 || $bIndex > 2
			|| !is_array($poses) || ($result['template_sources'] ?? null) !== ['BURST_A','BURST_A','BURST_A','BURST_A','BURST_A','BURST_B']) {
			throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Candidate selection provenance is incomplete');
		}
		$seen = [];
		foreach ($poses as $measurement) {
			$burst = $measurement['burst'] ?? null;
			$index = $measurement['index'] ?? null;
			$pose = $measurement['pose'] ?? null;
			if (!is_int($index) || !in_array($burst, ['BURST_A','BURST_B'], true)
				|| $index < 0 || $index >= ($burst === 'BURST_A' ? 10 : 3)
				|| $pose !== ($burst === 'BURST_A' ? $expectedPoses[intdiv($index, 2)] : 'centre')) {
				throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Candidate pose order is invalid');
			}
			foreach (['yaw','pitch'] as $field) {
				$value = $measurement[$field] ?? null;
				if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
					throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Candidate pose measurement is missing');
				}
			}
			$key = $burst . ':' . $index;
			if (isset($seen[$key])) throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Candidate pose is repeated');
			$seen[$key] = true;
		}
		foreach ($selected as $poseIndex => $index) {
			if (!is_int($index) || intdiv($index, 2) !== $poseIndex
				|| !isset($seen['BURST_A:' . $index]) || !isset($a[$index]) || !is_array($a[$index])
				|| !isset($embeddings[$poseIndex]) || $embeddings[$poseIndex] !== $a[$index]) {
				throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Selected pose is not a real validated candidate');
			}
		}
		foreach ([0,1,2] as $index) {
			if (!isset($seen['BURST_B:' . $index]) || !is_array($b[$index])) {
				throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Verification burst is incomplete');
			}
		}
		if ($embeddings[5] !== $b[$bIndex]) {
			throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Selected holdout is not a real frame');
		}
	}

	private function enrollV2(array $command, array $member, array $reference): array
	{
		$poseProtocol = $command['pose_protocol'] ?? null;
		if ($poseProtocol !== null && !self::supportsGuidedEnrollment($this->inference)) {
			return $this->rejected('UNSUPPORTED_POSE_PROTOCOL', 'Guided enrollment is unavailable on this provider');
		}
		if (in_array($poseProtocol, [self::GUIDED_SELECTION_PROTOCOL, self::GUIDED_ADAPTIVE_PROTOCOL], true)
			&& (!is_callable([$this->inference, 'supportsEnrollmentProtocol'])
				|| !$this->inference->supportsEnrollmentProtocol($poseProtocol))) {
			return $this->rejected('UNSUPPORTED_POSE_PROTOCOL', 'Candidate selection is unavailable on this provider');
		}
		$subjectId = (int)$reference['subject_id'];
		$clientAttemptId = !empty($command['client_attempt_id']) ? (string)$command['client_attempt_id'] : bin2hex(random_bytes(16));
		$burstA = $command['burst_a'] ?? [];
		$burstB = $command['burst_b'] ?? [];
		$expectedA = $poseProtocol === self::GUIDED_SELECTION_PROTOCOL ? self::GUIDED_SELECTION_BURST_A_COUNT : 5;
		if (count($burstA) !== $expectedA) {
			return $this->rejected('BURST_A_FRAME_COUNT', "Burst A requires exactly {$expectedA} quality frames");
		}
		if (count($burstB) !== 3) {
			return $this->rejected('BURST_B_FRAME_COUNT', 'Burst B requires exactly 3 quality frames');
		}
		if ($poseProtocol === self::GUIDED_ADAPTIVE_PROTOCOL) {
			$evidenceReason = $this->verifyAdaptiveCaptureEvidence($command, $burstA, $burstB);
			if ($evidenceReason !== null)
				return $this->rejected($evidenceReason, 'Candidate bytes, order, or departure could not be verified');
		}

		$encodeStarted = microtime(true);
		try {
			if (method_exists($this->inference, 'encodeEnrollmentV2')) {
				$contextsA = isset($command['capture_contexts_a']) && is_array($command['capture_contexts_a']) ? $command['capture_contexts_a'] : [];
				$contextsB = isset($command['capture_contexts_b']) && is_array($command['capture_contexts_b']) ? $command['capture_contexts_b'] : [];
				$method = new ReflectionMethod($this->inference, 'encodeEnrollmentV2');
				$encoded = $poseProtocol !== null
					? $this->inference->encodeEnrollmentV2($burstA, $burstB, $reference['embedding'], $contextsA, $contextsB, $poseProtocol)
					: ((($contextsA || $contextsB) && $method->getNumberOfParameters() >= 5)
					? $this->inference->encodeEnrollmentV2($burstA, $burstB, $reference['embedding'], $contextsA, $contextsB)
					: $this->inference->encodeEnrollmentV2($burstA, $burstB, $reference['embedding']));
			} else {
				$encoded = $this->inference->encodeEnrollment(array_merge($burstA, $burstB));
			}
			if ($poseProtocol === self::GUIDED_ADAPTIVE_PROTOCOL) {
				$deviceMeta = is_array($command['device_meta'] ?? null) ? $command['device_meta']
					: json_decode((string)($command['device_meta'] ?? '{}'), true);
				FaceErrorLogger::record('FACE_ENROLLMENT_ENCODE_TIMING', [
					'run_id'=>substr((string)($deviceMeta['run_id'] ?? ''), 0, 64),
					'encode_ms'=>round((microtime(true)-$encodeStarted)*1000, 1),
					'protocol'=>$poseProtocol]);
			}
		} catch (FaceEnrollmentException $exception) {
			FaceErrorLogger::record('ENROLLMENT_INFERENCE_FAILED', [
				'encode_ms'=>round((microtime(true)-$encodeStarted)*1000, 1),
				'member_id' => $command['member_id'],
				'branch_id' => $command['branch_id'],
				'actor_employee_id' => $command['actor_employee_id'],
				'reason' => $exception->reason,
				'message' => $exception->getMessage(),
			]);
			return $this->rejected($exception->reason, $exception->getMessage());
		} catch (Throwable $exception) {
			FaceErrorLogger::record('ENROLLMENT_INFERENCE_FAILED', [
				'encode_ms'=>round((microtime(true)-$encodeStarted)*1000, 1),
				'member_id' => $command['member_id'],
				'branch_id' => $command['branch_id'],
				'actor_employee_id' => $command['actor_employee_id'],
				'reason' => 'INFERENCE_FAILED',
			]);
			return $this->rejected('INFERENCE_FAILED', 'Face enrollment could not be completed');
		}

		$prepared = $this->validateInferenceResult($encoded, !empty($command['allow_uncalibrated_testing']), $poseProtocol);
		$sessionForTesting = $this->captureSessionsReady() ? $this->captureSession($command) : null;
		if ($sessionForTesting && $sessionForTesting['mode'] === 'REENROLLMENT' && !empty($prepared['testing_mode'])) {
			return $this->rejected('VALIDATION_ONLY_REENROLLMENT', 'Calibration is required before replacing an active enrollment');
		}
		$burstAEmbeddings = $poseProtocol === self::GUIDED_SELECTION_PROTOCOL
			? array_slice($prepared['embeddings'], 0, 5)
			: (!empty($encoded['burst_a_embeddings'])
			? $encoded['burst_a_embeddings']
			: (count($prepared['embeddings']) > 1 ? array_slice($prepared['embeddings'], 0, (int)ceil(count($prepared['embeddings']) / 2)) : $prepared['embeddings']));
		$burstBEmbeddings = !empty($encoded['burst_b_embeddings'])
			? $encoded['burst_b_embeddings']
			: (count($prepared['embeddings']) > 1 ? array_slice($prepared['embeddings'], (int)ceil(count($prepared['embeddings']) / 2)) : $prepared['embeddings']);

		$memberLock = $this->lockName((int)$command['member_id']);
		$lockName = 'face_enroll_tenant_' . $prepared['model_version_id'];
		$transactionStarted = false;
		try {
			$this->acquireLock($memberLock);
			$this->acquireLock($lockName);
			$this->conn->begin_transaction();
			$transactionStarted = true;

			$member = $this->loadMember($command['member_id'], $command['branch_id'], true);
			if (!$member) {
				throw new FaceEnrollmentException('NOT_PROGRAM_MEMBER', 'Selected child is not an active member of this program branch');
			}
			$session = $this->captureSessionsReady() ? $this->captureSession($command, true) : null;
			if ($session && ((int)$session['reference_id'] !== (int)$reference['id'] || !in_array($reference['status'], ['PENDING_ENROLLMENT'], true))) throw new FaceEnrollmentException('STALE_CAPTURE_SESSION', 'Pending reference has changed');
			$activeTemplates = (int)$this->scalar("SELECT COUNT(*) FROM face_template WHERE member_id = '{$command['member_id']}' AND status = 'ACTIVE'");
			if ($activeTemplates > 0 && (!$session || $session['mode'] !== 'REENROLLMENT')) {
				$this->conn->commit();
				$transactionStarted = false;
				return [
					'state' => 'ALREADY_ENROLLED',
					'member_id' => $command['member_id'],
					'template_count' => $activeTemplates,
				];
			}

			// Tenant-wide deduplication uses the same live, version-bound block
			// threshold as early setup.  A real inference client without that
			// policy is an unavailable safety check, never a local 0.75 fallback.
			$duplicatePolicy = $this->activeDuplicatePolicy((string)$prepared['calibration_policy_version'], (int)$prepared['model_version_id']);
			if (is_callable([$this->inference, 'identityPolicy']) && $duplicatePolicy === null) {
				throw new FaceEnrollmentException('CHECK_UNAVAILABLE', 'The active identity policy is unavailable or does not match this enrollment');
			}
			$dup = $this->findDuplicateIdentityV2($command, $prepared, $subjectId, $burstAEmbeddings, $burstBEmbeddings,
				(int)$member['contact_id'], $duplicatePolicy);
			if ($dup['decision'] === 'DUPLICATE_BLOCKED') {
				FaceErrorLogger::record('FACE_ENROLLMENT_DUPLICATE_IDENTITY', [
					'member_id' => $command['member_id'],
					'conflicting_member_id' => $dup['member_id'],
					'conflicting_member_name' => $dup['fullname'],
					'branch_id' => $command['branch_id'],
					'model_version_id' => $prepared['model_version_id'],
					'collision_score' => round($dup['score'], 7),
				]);
				throw new FaceEnrollmentException(
					'DUPLICATE_BLOCKED',
					"This face is already registered for {$dup['fullname']}. Each child must have their own unique face registered.",
					$dup['fullname'],
					$dup['member_id']
				);
			}
			if ($dup['decision'] === 'ADMIN_REVIEW') {
				FaceErrorLogger::record('FACE_ENROLLMENT_ADMIN_REVIEW', [
					'member_id' => $command['member_id'],
					'conflicting_member_id' => $dup['member_id'],
					'conflicting_member_name' => $dup['fullname'],
					'branch_id' => $command['branch_id'],
					'model_version_id' => $prepared['model_version_id'],
					'collision_score' => round($dup['score'], 7),
				]);
				throw new FaceEnrollmentException(
					'ADMIN_REVIEW',
					"Identity match requires biometric admin review before registration.",
					$dup['fullname'],
					$dup['member_id']
				);
			}

			// Record face_enrollment_attempt
			$thresholdSnapshot = json_encode([
				'verified_reference_threshold' => 0.90,
				'holdout_median_threshold' => 0.90,
				'holdout_min_threshold' => 0.85,
				'duplicate_review_threshold' => $duplicatePolicy['review_threshold'] ?? 0.75,
				'duplicate_block_threshold' => $duplicatePolicy['duplicate_block_threshold'] ?? 0.90,
				'duplicate_min_template_support' => $duplicatePolicy['min_template_support'] ?? 2,
				'duplicate_min_margin' => $duplicatePolicy['min_margin'] ?? 0.20,
				'calibration_policy_version' => $duplicatePolicy['policy_version'] ?? $prepared['calibration_policy_version'],
			], JSON_UNESCAPED_SLASHES);
			$deviceMetadata = !empty($command['device_meta']) ? (is_array($command['device_meta']) ? json_encode($command['device_meta'], JSON_UNESCAPED_SLASHES) : (string)$command['device_meta']) : (!empty($command['device_metadata']) ? (is_array($command['device_metadata']) ? json_encode($command['device_metadata'], JSON_UNESCAPED_SLASHES) : (string)$command['device_metadata']) : null);
			$venue = !empty($command['venue']) ? (string)$command['venue'] : (!empty($command['venue_meta']) ? (is_array($command['venue_meta']) ? json_encode($command['venue_meta'], JSON_UNESCAPED_SLASHES) : (string)$command['venue_meta']) : null);
			$referenceId = (int)$reference['id'];
			$drift = null;
			$review = false;
			if ($session && $session['mode'] === 'REENROLLMENT') {
				$oldReference = $this->getActiveReference((int)$member['contact_id']);
				if ($oldReference && (int)$oldReference['model_version_id'] === (int)$prepared['model_version_id']) {
					$drift = $this->cosine($oldReference['embedding'], $reference['embedding']);
					$review = $drift < 0.90;
				} else $review = true;
			}
			$generation = hash('sha256', (string)$prepared['model_version_id'] . ':' . (string)($prepared['preprocessing_version'] ?? ''));
			$attemptState = $review ? 'PENDING_ADMIN_REVIEW' : 'COMPLETED';
			$attemptDecision = $review ? 'PENDING_ADMIN_REVIEW' : 'APPROVED';
			$reasonCode = $review ? 'IDENTITY_DRIFT' : 'QUALITY_CONSENSUS_MATCH';
			$extraColumns = $session ? ',capture_session_id,identity_drift_score,model_version_id,recognition_generation' : '';
			$extraValues = $session ? ",'" . (int)$session['id'] . "'," . ($drift === null ? 'NULL' : (string)round($drift, 4)) . ",'" . (int)$prepared['model_version_id'] . "','{$generation}'" : '';

			$attemptSql = "INSERT INTO face_enrollment_attempt (client_attempt_id, subject_id, member_id, branch_id, reference_id, burst_a_count, burst_b_count, two_burst_state, decision, reason_code, threshold_snapshot, duplicate_candidate_result, actor_employee_id, device_metadata, venue, created_at, completed_at{$extraColumns}) VALUES ('{$this->escape($clientAttemptId)}', '{$subjectId}', '{$command['member_id']}', '{$command['branch_id']}', '{$referenceId}', " . count($burstA) . ", " . count($burstB) . ", '{$attemptState}', '{$attemptDecision}', '{$reasonCode}', '{$this->escape($thresholdSnapshot)}', '\"CLEAR\"', '{$command['actor_employee_id']}', " . ($deviceMetadata !== null ? "'{$this->escape($deviceMetadata)}'" : "NULL") . ", " . ($venue !== null ? "'{$this->escape($venue)}'" : "NULL") . ", CURRENT_TIMESTAMP, " . ($review ? 'NULL' : 'CURRENT_TIMESTAMP') . "{$extraValues})";
			$this->run($attemptSql);
			$attemptId = (int)$this->conn->insert_id;

			// Record face_capture_evidence for each frame (no raw image bytes)
			if (!empty($encoded['evidences'])) {
				if (!empty($encoded['evidences']['burst_a'])) {
					$this->recordCaptureEvidence($attemptId, null, $encoded['evidences']['burst_a'], $prepared['model_version_id'], $prepared['calibration_policy_version'], 'BURST_A');
				}
				if (!empty($encoded['evidences']['burst_b'])) {
					$this->recordCaptureEvidence($attemptId, null, $encoded['evidences']['burst_b'], $prepared['model_version_id'], $prepared['calibration_policy_version'], 'BURST_B');
				}
			}

			$this->insertAuthorization($command);
			$prepared['reenrollment'] = $session && $session['mode'] === 'REENROLLMENT';
			$prepared['pending_approval'] = $review;
			$command['capture_session_id'] = $session ? (int)$session['id'] : 0;
			$templateIds = [];
			foreach ($prepared['embeddings'] as $templateIndex => $embedding) {
				$templateSource = (string)($prepared['template_sources'][$templateIndex] ?? 'UNKNOWN');
				$templateIds[] = $this->insertTemplate($command, $prepared, $embedding, $subjectId, $attemptId, (int)$reference['id'], $templateSource);
			}
			$referenceStatus = $review ? 'PENDING_ENROLLMENT' : (!empty($prepared['testing_mode']) ? 'VALIDATION_ONLY' : 'ACTIVE');
			if ($session && $session['mode'] === 'REENROLLMENT' && !$review) {
				$this->run("UPDATE face_template SET status='SUPERSEDED',superseded_at=NOW() WHERE member_id='" . (int)$command['member_id'] . "' AND status='ACTIVE' AND (capture_session_id IS NULL OR capture_session_id <> '" . (int)$session['id'] . "')");
				$this->run("UPDATE face_identity_reference SET status='SUPERSEDED',superseded_at=NOW() WHERE subject_id='{$subjectId}' AND status='ACTIVE'");
			}
			$provenanceColumn = $this->conn->query("SHOW COLUMNS FROM face_identity_reference LIKE 'enrollment_session_id'");
			$referenceProvenance = $provenanceColumn && $provenanceColumn->num_rows > 0
				? ", enrollment_session_id = '{$attemptId}', preprocessing_version = '" . $this->escape((string)($prepared['preprocessing_version'] ?? '')) . "'" : '';
			$this->run("UPDATE face_identity_reference SET status = '{$referenceStatus}'{$referenceProvenance} WHERE id = '" . (int)$reference['id'] . "' AND status = 'PENDING_ENROLLMENT'");
			if ($session) $this->run("UPDATE face_enrollment_capture_session SET status='" . ($review ? 'PENDING_ADMIN_REVIEW' : 'COMPLETED') . "',completed_at=" . ($review ? 'NULL' : 'NOW()') . " WHERE id='" . (int)$session['id'] . "'");
			$this->conn->commit();
			$transactionStarted = false;

			return [
				'state' => $review ? 'PENDING_ADMIN_REVIEW' : $referenceStatus,
				'attempt_id' => $attemptId,
				'identity_drift_score' => $drift,
				'outcome' => 'CLEAR_NEW_PERSON',
				'testing_mode' => !empty($prepared['testing_mode']),
				'protocol_version' => 2,
				'member_id' => $command['member_id'],
				'contact_id' => (int)$member['contact_id'],
				'subject_id' => $subjectId,
				'reference_id' => (int)$reference['id'],
				'template_ids' => $templateIds,
				'template_count' => count($templateIds),
				'model_version_id' => $prepared['model_version_id'],
				'quality_summary' => $prepared['quality_summary'],
			];
		} catch (FaceEnrollmentException $exception) {
			if ($transactionStarted) {
				$this->conn->rollback();
			}
			if ($exception->reason === 'DUPLICATE_BLOCKED') {
				$referenceId = (int)($reference['id'] ?? 0);
				if ($referenceId > 0) {
					$this->run("UPDATE face_identity_reference SET status = 'REJECTED_DUPLICATE' WHERE id = '{$referenceId}' AND status = 'PENDING_ENROLLMENT'");
				}
			}
			$extra = [];
			if (!empty($exception->conflictingMemberName)) {
				$extra['conflicting_member_name'] = $exception->conflictingMemberName;
				$extra['conflicting_member_id'] = $exception->conflictingMemberId;
			}
			return $this->rejected($exception->reason, $exception->getMessage(), $extra);
		} catch (Throwable $exception) {
			if ($transactionStarted) {
				$this->conn->rollback();
			}
			throw $exception;
		} finally {
			$this->releaseLock($lockName);
			$this->releaseLock($memberLock);
		}
	}

	public function withdraw(int $memberId, int $actorEmployeeId, int $branchId): array
	{
		if ($memberId < 1 || $actorEmployeeId < 1 || $branchId < 1) {
			return $this->rejected('INVALID_ACTOR', 'Member, actor, and branch IDs must be positive');
		}
		if (!$this->one("SELECT id FROM member WHERE id = '{$memberId}' AND bid = '{$branchId}' LIMIT 1")) {
			return $this->rejected('NOT_PROGRAM_MEMBER', 'Selected child is not a member of the authorized branch');
		}
		$lockName = $this->lockName($memberId);
		$transactionStarted = false;
		$scanTokens = [];
		try {
			$this->acquireLock($lockName);
			$this->conn->begin_transaction();
			$transactionStarted = true;
			$this->one("SELECT id FROM member WHERE id = '{$memberId}' AND bid = '{$branchId}' LIMIT 1 FOR UPDATE");
			if ($this->captureSessionsReady()) {
				$open = $this->run("SELECT id FROM face_enrollment_capture_session WHERE member_id='{$memberId}' AND status IN ('ACTIVE','PENDING_ADMIN_REVIEW') FOR UPDATE");
				while ($row = $open->fetch_assoc()) $this->eraseSessionCandidates((int)$row['id']);
				$this->run("UPDATE face_enrollment_capture_session SET status='CANCELLED',reference_id=NULL,cleared_at=NOW() WHERE member_id='{$memberId}' AND status IN ('ACTIVE','PENDING_ADMIN_REVIEW')");
			}
			$this->run("UPDATE face_template SET status = 'REVOKED', revoked_at = CURRENT_TIMESTAMP WHERE member_id = '{$memberId}' AND status IN ('ACTIVE', 'AGING', 'REVALIDATION_REQUIRED', 'VALIDATION_ONLY', 'QUARANTINED')");
			$revoked = $this->conn->affected_rows;
			$this->run("UPDATE face_template SET encrypted_embedding = '' WHERE member_id = '{$memberId}' AND encrypted_embedding <> ''");
			$this->run("UPDATE face_identity_reference fir JOIN biometric_subject bs ON bs.id = fir.subject_id JOIN member m ON m.contact_id = bs.contact_id SET fir.status = 'REVOKED', fir.encrypted_embedding = '', fir.encrypted_portrait = '' WHERE m.id = '{$memberId}'");
			$this->run("UPDATE biometric_consent SET consent_status = 'WITHDRAWN', withdrawn_at = CURRENT_TIMESTAMP WHERE member_id = '{$memberId}' AND consent_status = 'PROGRAM_AUTHORIZED'");
			$studyTable = $this->conn->query("SHOW TABLES LIKE 'face_study_evidence'");
			if ($studyTable && $studyTable->num_rows) {
				$studySubject = $this->one("SELECT subject_id FROM face_study_consent WHERE member_id = '{$memberId}' AND withdrawn_at IS NULL LIMIT 1 FOR UPDATE");
				$subjectSql = $studySubject ? " OR gallery_subject_id = '" . $this->escape($studySubject['subject_id']) . "' OR selected_subject_id = '" . $this->escape($studySubject['subject_id']) . "'" : '';
				$this->run("DELETE FROM face_study_evidence WHERE member_id = '{$memberId}'{$subjectSql}");
				$this->run("UPDATE face_study_consent SET withdrawn_at = CURRENT_TIMESTAMP, document_sha256 = NULL WHERE member_id = '{$memberId}' AND withdrawn_at IS NULL");
			}
			$openScans = $this->run("SELECT DISTINCT s.scan_token FROM attendance_scan s JOIN attendance_scan_roster r ON r.scan_id = s.id WHERE r.member_id = '{$memberId}' AND s.status = 'ACTIVE' FOR UPDATE");
			while ($openScan = $openScans->fetch_assoc()) $scanTokens[] = (string)$openScan['scan_token'];
			$this->run("UPDATE attendance_scan s JOIN attendance_scan_roster r ON r.scan_id = s.id SET s.status = 'CANCELLED' WHERE r.member_id = '{$memberId}' AND s.status = 'ACTIVE'");
			$this->conn->commit();
			$transactionStarted = false;
			FaceErrorLogger::record('FACE_BIOMETRIC_WITHDRAWN_AND_ERASED', [
				'member_id' => $memberId,
				'actor_employee_id' => $actorEmployeeId,
				'revoked_templates' => (int)$revoked,
			]);
			if (method_exists($this->inference, 'deleteScan')) {
				foreach ($scanTokens as $scanToken) {
					try { $this->inference->deleteScan($scanToken); } catch (Throwable $ignored) { /* Cancelled database scans cannot be used. */ }
				}
			}
			return [
				'state' => $revoked > 0 ? 'REVOKED' : 'NOT_ENROLLED',
				'member_id' => $memberId,
				'revoked_templates' => (int)$revoked,
				'cache_invalidation_required' => true,
			];
		} finally {
			if ($transactionStarted) {
				$this->conn->rollback();
			}
			$this->releaseLock($lockName);
		}
	}

	public function reviewReenrollmentAttempt(int $attemptId, string $decision, int $adminActorId, bool $inPersonVerified, string $reason, int $adminPositionId = 0): array
	{
		if ($adminPositionId !== 1) return $this->rejected('UNAUTHORIZED_ADMIN_OVERRIDE', 'Administrator position is required');
		$reason = trim($reason);
		if ($attemptId < 1 || !in_array($decision, ['APPROVED','REJECTED'], true) || !$inPersonVerified || $reason === '' || strlen($reason) > 255)
			return $this->rejected('INVALID_ADMIN_REVIEW', 'In-person verification and a reason are required');
		$attempt = $this->one("SELECT member_id,branch_id,model_version_id FROM face_enrollment_attempt WHERE id='{$attemptId}' AND decision='PENDING_ADMIN_REVIEW'");
		if (!$attempt) return $this->rejected('REVIEW_NOT_PENDING', 'Review is no longer pending');
		$memberId = (int)$attempt['member_id'];
		$memberLock = $this->lockName($memberId);
		$tenantLock = $this->identityLockName((int)$attempt['branch_id'], (int)$attempt['model_version_id']);
		$this->acquireLock($memberLock);
		try {
			$this->acquireLock($tenantLock);
			try {
				$this->conn->begin_transaction();
				try {
					$attempt = $this->one("SELECT * FROM face_enrollment_attempt WHERE id='{$attemptId}' FOR UPDATE");
					$sessionId = (int)($attempt['capture_session_id'] ?? 0);
					$session = $this->one("SELECT * FROM face_enrollment_capture_session WHERE id='{$sessionId}' FOR UPDATE");
					if (!$session || $attempt['decision'] !== 'PENDING_ADMIN_REVIEW' || $session['status'] !== 'PENDING_ADMIN_REVIEW' || strtotime($session['expires_at']) <= time()) throw new FaceEnrollmentException('REVIEW_NOT_PENDING', 'Review expired or was replaced');
					$consent = $this->one("SELECT consent_status FROM biometric_consent WHERE member_id='{$memberId}' ORDER BY id DESC LIMIT 1 FOR UPDATE");
					$currentModel = (int)(FaceAttendanceConfig::getEnv('FACE_MODEL_VERSION_ID') ?: 0);
					$reference = $this->one("SELECT preprocessing_version,model_version_id,status FROM face_identity_reference WHERE id='" . (int)$attempt['reference_id'] . "' FOR UPDATE");
					$generation = $reference ? hash('sha256', (string)$reference['model_version_id'] . ':' . (string)$reference['preprocessing_version']) : '';
					$registeredGeneration = $generation;
					$preprocessingColumn = $this->conn->query("SHOW COLUMNS FROM face_model_version LIKE 'preprocessing_version'");
					if ($preprocessingColumn && $preprocessingColumn->num_rows > 0) {
						$model = $this->one("SELECT preprocessing_version FROM face_model_version WHERE id='" . (int)$attempt['model_version_id'] . "' LIMIT 1");
						$registeredGeneration = $model ? hash('sha256', (string)$attempt['model_version_id'] . ':' . (string)$model['preprocessing_version']) : '';
					}
					if (!$consent || $consent['consent_status'] !== 'PROGRAM_AUTHORIZED' || ($currentModel && $currentModel !== (int)$attempt['model_version_id']) || !hash_equals((string)$attempt['recognition_generation'], $generation) || !hash_equals((string)$attempt['recognition_generation'], $registeredGeneration)) {
						$this->eraseSessionCandidates($sessionId);
						$this->run("UPDATE face_enrollment_capture_session SET status='CLEARED',reference_id=NULL,cleared_at=NOW() WHERE id='{$sessionId}'");
						$this->conn->commit();
						return $this->rejected('REVIEW_CONTEXT_CHANGED', 'Consent or recognition generation changed; recapture required');
					}
					if ($decision === 'APPROVED') {
						if (!$reference || $reference['status'] !== 'PENDING_ENROLLMENT' || (int)$this->scalar("SELECT COUNT(*) FROM face_template WHERE capture_session_id='{$sessionId}' AND status='PENDING_APPROVAL'") < 1) throw new FaceEnrollmentException('REVIEW_CANDIDATE_MISSING', 'Review candidate is unavailable');
						$this->run("UPDATE face_template SET status='SUPERSEDED',superseded_at=NOW() WHERE member_id='{$memberId}' AND status='ACTIVE'");
						$this->run("UPDATE face_identity_reference SET status='SUPERSEDED',superseded_at=NOW() WHERE subject_id='" . (int)$attempt['subject_id'] . "' AND status='ACTIVE'");
						$this->run("UPDATE face_template SET status='ACTIVE' WHERE capture_session_id='{$sessionId}' AND status='PENDING_APPROVAL'");
						$this->run("UPDATE face_identity_reference SET status='ACTIVE' WHERE id='" . (int)$attempt['reference_id'] . "' AND status='PENDING_ENROLLMENT'");
						$this->run("UPDATE face_enrollment_capture_session SET status='COMPLETED',completed_at=NOW() WHERE id='{$sessionId}'");
					} else {
						$this->eraseSessionCandidates($sessionId);
						$this->run("UPDATE face_enrollment_capture_session SET status='CLEARED',reference_id=NULL,cleared_at=NOW() WHERE id='{$sessionId}'");
					}
					$this->run("UPDATE face_enrollment_attempt SET decision='{$decision}',two_burst_state='COMPLETED',admin_override_actor_id='{$adminActorId}',admin_override_reason='" . $this->escape($reason) . "',admin_override_at=NOW(),completed_at=NOW() WHERE id='{$attemptId}'");
					$this->conn->commit();
					return ['state'=>$decision,'attempt_id'=>$attemptId];
				} catch (Throwable $e) { $this->conn->rollback(); throw $e; }
			} finally { $this->releaseLock($tenantLock); }
		} catch (FaceEnrollmentException $e) { return $this->rejected($e->reason, $e->getMessage()); }
		finally { $this->releaseLock($memberLock); }
	}

	public function listReenrollmentReviews(int $branchId): array
	{
		$reviews = [];
		$result = $this->run("SELECT a.id attempt_id,a.member_id,TRIM(CONCAT(c.fname,' ',c.lname)) member_name,a.identity_drift_score,a.created_at,s.expires_at FROM face_enrollment_attempt a JOIN face_enrollment_capture_session s ON s.id=a.capture_session_id JOIN member m ON m.id=a.member_id JOIN contact c ON c.id=m.contact_id WHERE a.branch_id='{$branchId}' AND a.decision='PENDING_ADMIN_REVIEW' AND s.status='PENDING_ADMIN_REVIEW' AND s.expires_at>NOW() ORDER BY a.created_at ASC LIMIT 100");
		while ($row = $result->fetch_assoc()) {
			$reviews[] = ['attempt_id'=>(int)$row['attempt_id'],'member_id'=>(int)$row['member_id'],'member_name'=>(string)$row['member_name'],'identity_drift_score'=>$row['identity_drift_score'] === null ? null : (float)$row['identity_drift_score'],'created_at'=>(string)$row['created_at'],'expires_at'=>(string)$row['expires_at']];
		}
		return ['state'=>'READY','reviews'=>$reviews];
	}

	private function normalizeCommand(array $command): array
	{
		if (array_key_exists('pose_protocol', $command)
			&& (!in_array($command['pose_protocol'], [self::GUIDED_POSE_PROTOCOL, self::GUIDED_SELECTION_PROTOCOL, self::GUIDED_ADAPTIVE_PROTOCOL], true)
				|| !isset($command['burst_a'], $command['burst_b']))) {
			throw new FaceEnrollmentException('UNSUPPORTED_POSE_PROTOCOL', 'Guided enrollment requires a supported protocol and named bursts');
		}
		$required = ['branch_id', 'member_id', 'actor_employee_id', 'notice_version', 'purpose'];
		foreach ($required as $field) {
			if (!array_key_exists($field, $command) || $command[$field] === '') {
				throw new FaceEnrollmentException('INVALID_COMMAND', "Missing enrollment field: {$field}");
			}
		}
		$command['branch_id'] = (int)$command['branch_id'];
		$command['member_id'] = (int)$command['member_id'];
		$command['actor_employee_id'] = (int)$command['actor_employee_id'];
		if ($command['branch_id'] < 1 || $command['member_id'] < 1 || $command['actor_employee_id'] < 1) {
			throw new FaceEnrollmentException('INVALID_COMMAND', 'Enrollment identifiers must be positive');
		}
		if (!filter_var($command['teacher_confirmed_member'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
			throw new FaceEnrollmentException('MEMBER_CONFIRMATION_REQUIRED', 'Teacher must confirm the selected child before enrollment');
		}

		if (isset($command['burst_a']) || isset($command['burst_b'])) {
			$expectedA = ($command['pose_protocol'] ?? null) === self::GUIDED_SELECTION_PROTOCOL ? self::GUIDED_SELECTION_BURST_A_COUNT : 5;
			if (!isset($command['burst_a']) || !is_array($command['burst_a']) || count($command['burst_a']) !== $expectedA) {
				throw new FaceEnrollmentException('BURST_A_FRAME_COUNT', "Burst A requires exactly {$expectedA} frames");
			}
			if (!isset($command['burst_b']) || !is_array($command['burst_b']) || count($command['burst_b']) !== 3) {
				throw new FaceEnrollmentException('BURST_B_FRAME_COUNT', 'Burst B requires exactly 3 frames');
			}
			$totalBytes = 0;
			foreach (array_merge($command['burst_a'], $command['burst_b']) as $frame) {
				if (!is_string($frame) || $frame === '' || strlen($frame) > $this->maxFrameBytes) {
					throw new FaceEnrollmentException('INVALID_FRAME', 'Enrollment frames must be non-empty and at most one megabyte each');
				}
				$totalBytes += strlen($frame);
			}
			if (($command['pose_protocol'] ?? null) === self::GUIDED_SELECTION_PROTOCOL && $totalBytes > self::GUIDED_SELECTION_MAX_TOTAL_BYTES) {
				throw new FaceEnrollmentException('ENROLLMENT_PAYLOAD_TOO_LARGE', 'Face images are too large; lower camera resolution and try again');
			}
		} elseif (isset($command['frames'])) {
			if (!is_array($command['frames']) || count($command['frames']) < 7 || count($command['frames']) > 10) {
				throw new FaceEnrollmentException('FRAME_COUNT', 'Enrollment requires 7 to 10 frames');
			}
			foreach ($command['frames'] as $frame) {
				if (!is_string($frame) || $frame === '' || strlen($frame) > $this->maxFrameBytes) {
					throw new FaceEnrollmentException('INVALID_FRAME', 'Enrollment frames must be non-empty and at most one megabyte each');
				}
			}
		} else {
			throw new FaceEnrollmentException('INVALID_COMMAND', 'Missing enrollment frames');
		}

		$command['notice_version'] = (string)$command['notice_version'];
		$command['purpose'] = (string)$command['purpose'];
		if (strlen($command['notice_version']) > 64 || strlen($command['purpose']) > 100) {
			throw new FaceEnrollmentException('INVALID_COMMAND', 'Enrollment notice version or purpose is too long');
		}
		return $command;
	}

	private function loadMember(int $memberId, int $branchId, bool $forUpdate)
	{
		$lock = $forUpdate ? ' FOR UPDATE' : '';
		return $this->one("SELECT id, contact_id, status, park, bid FROM member WHERE id = '{$memberId}' AND bid = '{$branchId}' AND status = '1' AND park = '0' LIMIT 1{$lock}");
	}

	private function validateInferenceResult(array $result, bool $allowUncalibratedTesting = false, ?string $poseProtocol = null): array
	{
		$guided = in_array($poseProtocol, [self::GUIDED_POSE_PROTOCOL, self::GUIDED_SELECTION_PROTOCOL, self::GUIDED_ADAPTIVE_PROTOCOL], true);
		if ($guided) $this->validateGuidedResult($result);
		$verificationPassed = !empty($result['verification_passed']);
		$uncalibratedTesting = $allowUncalibratedTesting
			&& !$verificationPassed
			&& (string)($result['verification_reason'] ?? '') === 'VERIFY_THRESHOLD_NOT_MET';
		if (!$verificationPassed && !$uncalibratedTesting) {
			throw new FaceEnrollmentException('VERIFICATION_FAILED', (string)($result['verification_reason'] ?? 'Same-day verification failed'));
		}
		$modelVersionId = isset($result['model_version_id']) ? (int)$result['model_version_id'] : 0;
		$modelVersion = trim((string)($result['model_version'] ?? ''));
		$providerId = (string)($result['provider_id'] ?? '');
		$providerVersion = (string)($result['provider_version'] ?? '');
		$policyVersion = (string)($result['calibration_policy_version'] ?? '');
		$embeddings = isset($result['embeddings']) && is_array($result['embeddings']) ? $result['embeddings'] : [];
		$dimension = isset($result['embedding_dimension']) ? (int)$result['embedding_dimension'] : 0;
		if ($modelVersionId < 1 || $providerId === '' || $providerVersion === '' || $policyVersion === '' || count($embeddings) < 1 || count($embeddings) > ($guided ? self::GUIDED_TEMPLATE_COUNT : 3) || $dimension < 1) {
			throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Enrollment inference result is incomplete');
		}
		$model = $this->one("SELECT id, model_name, provider_id, provider_version, policy_version, embedding_dimension, status FROM face_model_version WHERE id = '{$modelVersionId}' LIMIT 1");
		if (!$model || (string)$model['status'] !== 'ACTIVE'
			|| (string)$model['provider_id'] !== $providerId
			|| (string)$model['provider_version'] !== $providerVersion
			|| ($modelVersion !== '' && (string)$model['model_name'] !== $modelVersion)
			|| (string)$model['policy_version'] !== $policyVersion) {
			throw new FaceEnrollmentException('PROVIDER_MODEL_MISMATCH', 'Enrollment provider, model, and calibration versions do not match');
		}
		if ((int)$model['embedding_dimension'] !== $dimension) {
			throw new FaceEnrollmentException('MODEL_DIMENSION_MISMATCH', 'Enrollment embedding dimension does not match the model registry');
		}
		$preprocessingColumn = $this->conn->query("SHOW COLUMNS FROM face_model_version LIKE 'preprocessing_version'");
		if ($preprocessingColumn && $preprocessingColumn->num_rows > 0) {
			$registered = $this->one("SELECT preprocessing_version FROM face_model_version WHERE id='{$modelVersionId}' LIMIT 1");
			if (!$registered || (string)$registered['preprocessing_version'] !== (string)($result['preprocessing_version'] ?? '')) {
				throw new FaceEnrollmentException('PROVIDER_MODEL_MISMATCH', 'Inference preprocessing generation does not match the model registry');
			}
		}
		foreach ($embeddings as $embedding) {
			if (!is_array($embedding) || count($embedding) !== $dimension) {
				throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Enrollment embedding dimensions are invalid');
			}
			foreach ($embedding as $value) {
				if (!is_numeric($value) || !is_finite((float)$value)) {
					throw new FaceEnrollmentException('INVALID_INFERENCE_RESULT', 'Enrollment embedding contains an invalid value');
				}
			}
		}
		$quality = isset($result['quality_summary']) && is_array($result['quality_summary']) ? $result['quality_summary'] : [];
		if (empty($quality['frames_accepted']) || (int)$quality['frames_accepted'] < 7) {
			throw new FaceEnrollmentException('QUALITY_FAILED', 'Enrollment quality requirements were not met');
		}
		if ($uncalibratedTesting) {
			$quality['enrollment_mode'] = 'UNCALIBRATED_TEST';
		}
		return [
			'model_version_id' => $modelVersionId,
			'provider_id' => $providerId,
			'provider_version' => $providerVersion,
			'calibration_policy_version' => $policyVersion,
			'preprocessing_version' => (string)($result['preprocessing_version'] ?? ''),
			'embeddings' => $embeddings,
			'template_sources' => isset($result['template_sources']) && is_array($result['template_sources']) ? array_values($result['template_sources']) : [],
			'embedding_dimension' => $dimension,
			'quality_summary' => $quality,
			'testing_mode' => $uncalibratedTesting,
		];
	}

	private function insertAuthorization(array $command): void
	{
		$notice = $this->escape($command['notice_version']);
		$purpose = $this->escape($command['purpose']);
		$this->run("INSERT INTO biometric_consent (member_id, guardian_contact_id, notice_version, purpose, consent_status, verification_method, actor_employee_id, consented_at) VALUES ('{$command['member_id']}', NULL, '{$notice}', '{$purpose}', 'PROGRAM_AUTHORIZED', 'PROGRAM_PARTICIPATION', '{$command['actor_employee_id']}', CURRENT_TIMESTAMP)");
	}

	private function findDuplicateIdentity(array $command, array $prepared, ?float $blockThreshold = null): ?array
	{
		$newCentroid = $this->centroid($prepared['embeddings']);
		$modelVersionId = (int)$prepared['model_version_id'];
		$dimension = (int)$prepared['embedding_dimension'];
		$result = $this->run(
			"SELECT ft.member_id, ft.encrypted_embedding, ft.embedding_dimension " .
			"FROM face_template ft INNER JOIN member m ON m.id = ft.member_id " .
			"WHERE ft.status = 'ACTIVE' " .
			"AND ft.model_version_id = '{$modelVersionId}' " .
			"AND ft.member_id <> '{$command['member_id']}' " .
			"AND m.bid = '{$command['branch_id']}' AND m.status = '1' AND m.park = '0' " .
			"ORDER BY ft.member_id, ft.id"
		);
		$members = [];
		if ($result instanceof mysqli_result) {
			while ($row = $result->fetch_assoc()) {
				if ((int)$row['embedding_dimension'] !== $dimension) {
					throw new FaceEnrollmentException('IDENTITY_INDEX_UNAVAILABLE', 'Face identity template index has invalid dimension');
				}
				try {
					$embedding = $this->cipher->decrypt((string)$row['encrypted_embedding']);
				} catch (Throwable $exception) {
					throw new FaceEnrollmentException('IDENTITY_INDEX_UNAVAILABLE', 'Face identity template index cannot be decrypted');
				}
				if (count($embedding) !== $dimension) {
					throw new FaceEnrollmentException('IDENTITY_INDEX_UNAVAILABLE', 'Face identity template index has invalid dimension');
				}
				$memberId = (int)$row['member_id'];
				if (!isset($members[$memberId])) {
					$members[$memberId] = [];
				}
				$members[$memberId][] = $embedding;
			}
			$result->free();
		}

		$highest = null;
		foreach ($members as $memberId => $embeddings) {
			$score = $this->cosine($newCentroid, $this->centroid($embeddings));
			foreach ($prepared['embeddings'] as $newEmbedding) {
				foreach ($embeddings as $existingEmbedding) {
					$score = max($score, $this->cosine($newEmbedding, $existingEmbedding));
				}
			}
			$threshold = $blockThreshold !== null ? $blockThreshold : max(0.90, $this->duplicateThreshold);
			if ($score >= $threshold && ($highest === null || $score > $highest['score'])) {
				$highest = ['member_id' => (int)$memberId, 'score' => $score];
			}
		}
		return $highest;
	}

	private function findDuplicateIdentityV2(array $command, array $prepared, int $subjectId, array $burstAEmbeddings, array $burstBEmbeddings, int $contactId = 0, ?array $duplicatePolicy = null): array
	{
		$modelVersionId = (int)$prepared['model_version_id'];
		$dimension = (int)$prepared['embedding_dimension'];
		$result = $this->run(
			"SELECT ft.id, ft.subject_id, ft.member_id, ft.encrypted_embedding, ft.embedding_dimension, " .
			"COALESCE(bs.contact_id, m.contact_id) AS contact_id, TRIM(CONCAT(c.fname, ' ', c.lname)) AS fullname " .
			"FROM face_template ft " .
			"LEFT JOIN biometric_subject bs ON bs.id = ft.subject_id " .
			"LEFT JOIN member m ON m.id = ft.member_id " .
			"LEFT JOIN contact c ON c.id = COALESCE(bs.contact_id, m.contact_id) " .
			"WHERE ft.status = 'ACTIVE' " .
			"AND ft.model_version_id = '{$modelVersionId}' " .
			"AND ft.member_id <> '{$command['member_id']}' " .
			($contactId > 0 ? "AND (COALESCE(bs.contact_id, m.contact_id) IS NULL OR COALESCE(bs.contact_id, m.contact_id) <> '{$contactId}') " : "") .
			($subjectId > 0 ? "AND (ft.subject_id IS NULL OR ft.subject_id <> '{$subjectId}') " : "") .
			"ORDER BY ft.member_id, ft.id"
		);
		$subjects = [];
		if ($result instanceof mysqli_result) {
			while ($row = $result->fetch_assoc()) {
				if ((int)$row['embedding_dimension'] !== $dimension) {
					throw new FaceEnrollmentException('IDENTITY_INDEX_UNAVAILABLE', 'Face identity template index has invalid dimension');
				}
				try {
					$embedding = $this->cipher->decrypt((string)$row['encrypted_embedding']);
				} catch (Throwable $exception) {
					throw new FaceEnrollmentException('IDENTITY_INDEX_UNAVAILABLE', 'Face identity template index cannot be decrypted');
				}
				if (count($embedding) !== $dimension) {
					throw new FaceEnrollmentException('IDENTITY_INDEX_UNAVAILABLE', 'Face identity template has invalid dimension');
				}
				$candKey = !empty($row['contact_id']) ? 'c_' . $row['contact_id'] : 'm_' . $row['member_id'];
				if (!isset($subjects[$candKey])) {
					$subjects[$candKey] = [
						'member_id' => (int)$row['member_id'],
						'contact_id' => (int)$row['contact_id'],
						'fullname' => !empty($row['fullname']) ? $row['fullname'] : "Student #{$row['member_id']}",
						'embeddings' => [],
					];
				}
				$subjects[$candKey]['embeddings'][] = $embedding;
			}
			$result->free();
		}

		// Also check active references from other subjects in face_identity_reference
		$refCheck = $this->conn->query("SHOW TABLES LIKE 'face_identity_reference'");
		if ($refCheck && $refCheck->num_rows > 0) {
			$refResult = $this->conn->query(
				"SELECT fir.id, fir.subject_id, fir.encrypted_embedding, fir.embedding_dimension, " .
				"bs.contact_id, TRIM(CONCAT(c.fname, ' ', c.lname)) AS fullname " .
				"FROM face_identity_reference fir " .
				"JOIN biometric_subject bs ON bs.id = fir.subject_id " .
				"JOIN contact c ON c.id = bs.contact_id " .
				"WHERE fir.status = 'ACTIVE' " .
				"AND fir.model_version_id = '{$modelVersionId}' " .
				($contactId > 0 ? "AND bs.contact_id <> '{$contactId}' " : "") .
				($subjectId > 0 ? "AND fir.subject_id <> '{$subjectId}' " : "") .
				"ORDER BY fir.id"
			);
			if ($refResult) {
				while ($row = $refResult->fetch_assoc()) {
					if ((int)$row['embedding_dimension'] !== $dimension) {
						throw new FaceEnrollmentException('IDENTITY_INDEX_UNAVAILABLE', 'Face identity reference index has invalid dimension');
					}
					try {
						$embedding = $this->cipher->decrypt((string)$row['encrypted_embedding']);
					} catch (Throwable $exception) {
						throw new FaceEnrollmentException('IDENTITY_INDEX_UNAVAILABLE', 'Face identity reference index cannot be decrypted');
					}
					if (count($embedding) !== $dimension) {
						throw new FaceEnrollmentException('IDENTITY_INDEX_UNAVAILABLE', 'Face identity reference has invalid dimension');
					}
					$candKey = 'c_' . $row['contact_id'];
					if (!isset($subjects[$candKey])) {
						$subjects[$candKey] = [
							'member_id' => 0,
							'contact_id' => (int)$row['contact_id'],
							'fullname' => !empty($row['fullname']) ? $row['fullname'] : "Student (Contact #{$row['contact_id']})",
							'embeddings' => [],
						];
					}
					$subjects[$candKey]['embeddings'][] = $embedding;
				}
			}
		}

		$blockThreshold = $duplicatePolicy !== null
			? (float)$duplicatePolicy['duplicate_block_threshold']
			: max(0.90, min(1.0, (float)$this->duplicateThreshold));
		$minimumSupport = $duplicatePolicy !== null ? (int)$duplicatePolicy['min_template_support'] : 2;
		$conflicts = [];
		foreach ($subjects as $candKey => $cand) {
				// Score every capture against the subject, not the single best
				// template pair. A duplicate requires two independent stored
				// references to support nearly every frame in both bursts.
				$metricsA = $this->duplicateBurstMetrics($burstAEmbeddings, $cand['embeddings'], $blockThreshold, $minimumSupport);
				$metricsB = $this->duplicateBurstMetrics($burstBEmbeddings, $cand['embeddings'], $blockThreshold, $minimumSupport);
				$score = max($metricsA['median'], $metricsB['median']);
				$bothBurstsHigh = $metricsA['high_count'] >= 4
					&& $metricsB['high_count'] >= 3
					&& $metricsA['median'] >= $blockThreshold
					&& $metricsB['median'] >= $blockThreshold;
				if ($bothBurstsHigh) {
					$conflicts[] = [
						'decision' => 'DUPLICATE_BLOCKED',
						'member_id' => $cand['member_id'],
						'fullname' => $cand['fullname'],
						'score' => $score,
						'burst_a' => $metricsA,
						'burst_b' => $metricsB,
					];
					continue;
				}
				if ($metricsA['high_count'] >= 4 || $metricsB['high_count'] >= 3) {
					$conflicts[] = [
						'decision' => 'ADMIN_REVIEW',
						'member_id' => $cand['member_id'],
						'fullname' => $cand['fullname'],
						'score' => $score,
						'burst_a' => $metricsA,
						'burst_b' => $metricsB,
					];
				}
		}

		if (!empty($conflicts)) {
			usort($conflicts, static function (array $left, array $right): int {
				if ($left['decision'] !== $right['decision']) {
					return $left['decision'] === 'DUPLICATE_BLOCKED' ? -1 : 1;
				}
				return $right['score'] <=> $left['score'];
			});
			return $conflicts[0];
		}
		return ['decision' => 'CLEAR', 'score' => 0.0];
	}

	private function duplicateBurstMetrics(array $captures, array $templates, float $threshold, int $minimumSupport = 2): array
	{
		$scores = [];
		$highCount = 0;
		foreach ($captures as $capture) {
			$templateScores = [];
			foreach ($templates as $template) {
				$templateScores[] = $this->cosine($capture, $template);
			}
			rsort($templateScores, SORT_NUMERIC);
			if (count($templateScores) < 2) {
				$scores[] = $templateScores ? (float)$templateScores[0] : -1.0;
				continue;
			}
			$subjectScore = ((float)$templateScores[0] + (float)$templateScores[1]) / 2.0;
			$scores[] = $subjectScore;
			$support = count(array_filter($templateScores, static function ($score) use ($threshold): bool {
				return $score >= $threshold;
			}));
			if ($subjectScore >= $threshold && $support >= $minimumSupport) {
				$highCount++;
			}
		}
		sort($scores, SORT_NUMERIC);
		$count = count($scores);
		$median = -1.0;
		if ($count > 0) {
			$middle = intdiv($count, 2);
			$median = ($count % 2 === 1)
				? (float)$scores[$middle]
				: ((float)$scores[$middle - 1] + (float)$scores[$middle]) / 2.0;
		}
		return ['median' => $median, 'high_count' => $highCount, 'frame_count' => $count];
	}

	private function recordCaptureEvidence(?int $enrollmentAttemptId, ?int $scanAttemptId, array $evidences, int $modelVersionId, string $policyVersion, string $burstLabel): void
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

	private function centroid(array $embeddings): array
	{
		if (empty($embeddings) || !isset($embeddings[0]) || !is_array($embeddings[0])) {
			return [];
		}
		$dimension = count($embeddings[0]);
		if ($dimension < 1) {
			return [];
		}
		$centroid = array_fill(0, $dimension, 0.0);
		foreach ($embeddings as $embedding) {
			if (!is_array($embedding) || count($embedding) !== $dimension) {
				continue;
			}
			foreach ($embedding as $index => $value) {
				$centroid[$index] += (float)$value;
			}
		}
		$count = count($embeddings);
		if ($count < 1) {
			return [];
		}
		foreach ($centroid as $index => $value) {
			$centroid[$index] = $value / $count;
		}
		return $centroid;
	}

	private function cosine(array $first, array $second): float
	{
		if (!$first || count($first) !== count($second)) {
			return -1.0;
		}
		$dot = 0.0;
		$firstNorm = 0.0;
		$secondNorm = 0.0;
		foreach ($first as $index => $value) {
			$left = (float)$value;
			$right = (float)$second[$index];
			$dot += $left * $right;
			$firstNorm += $left * $left;
			$secondNorm += $right * $right;
		}
		$denominator = sqrt($firstNorm) * sqrt($secondNorm);
		return $denominator > 0.0 ? $dot / $denominator : -1.0;
	}

	private function insertTemplate(array $command, array $prepared, array $embedding, int $subjectId = 0, int $attemptId = 0, int $referenceId = 0, string $templateSource = 'UNKNOWN'): int
	{
		$encrypted = $this->escape($this->cipher->encrypt($embedding));
		$qualitySummary = $prepared['quality_summary'];
		$qualitySummary['template_source'] = in_array($templateSource, ['BURST_A', 'BURST_B'], true) ? $templateSource : 'UNKNOWN';
		$qualitySummary['source'] = !empty($prepared['reenrollment']) ? 'REENROLLMENT' : 'ENROLLMENT';
		$qualitySummary['created_by_actor_id'] = (int)($command['actor_employee_id'] ?? 0);
		$qualitySummary['enrollment_session_id'] = $attemptId;
		$qualitySummary['preprocessing_version'] = (string)($prepared['preprocessing_version'] ?? '');
		$quality = $this->escape(json_encode($qualitySummary, JSON_UNESCAPED_SLASHES));
		$status = !empty($prepared['pending_approval']) ? 'PENDING_APPROVAL' : (!empty($prepared['testing_mode']) ? 'VALIDATION_ONLY' : 'ACTIVE');
		$provenanceColumn = $this->conn->query("SHOW COLUMNS FROM face_template LIKE 'enrollment_session_id'");
		$hasProvenance = $provenanceColumn && $provenanceColumn->num_rows > 0;
		$extraColumns = $hasProvenance ? ', source, enrollment_session_id, created_by_actor_id, preprocessing_version' : '';
		if (!empty($command['capture_session_id'])) $extraColumns .= ', capture_session_id';
		$source = $this->escape($qualitySummary['source']);
		$preprocessing = $this->escape($qualitySummary['preprocessing_version']);
		$actorId = (int)($command['actor_employee_id'] ?? 0);
		$extraValues = $hasProvenance ? ", '{$source}', " . ($attemptId > 0 ? (string)$attemptId : 'NULL') . ", " . ($actorId > 0 ? (string)$actorId : 'NULL') . ", '{$preprocessing}'" : '';
		if (!empty($command['capture_session_id'])) $extraValues .= ", '" . (int)$command['capture_session_id'] . "'";
		if ($this->checkV2Columns()) {
			$subSql = $subjectId > 0 ? "'{$subjectId}'" : "NULL";
			$attSql = $attemptId > 0 ? "'{$attemptId}'" : "NULL";
			$refSql = $referenceId > 0 ? "'{$referenceId}'" : "NULL";
			$this->run("INSERT INTO face_template (member_id, subject_id, enrollment_attempt_id, reference_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, quality_summary, status{$extraColumns}) VALUES ('{$command['member_id']}', {$subSql}, {$attSql}, {$refSql}, '{$prepared['model_version_id']}', '{$encrypted}', '{$this->escape($this->cipher->keyId())}', '{$prepared['embedding_dimension']}', '{$quality}', '{$status}'{$extraValues})");
		} else {
			$this->run("INSERT INTO face_template (member_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, quality_summary, status{$extraColumns}) VALUES ('{$command['member_id']}', '{$prepared['model_version_id']}', '{$encrypted}', '{$this->escape($this->cipher->keyId())}', '{$prepared['embedding_dimension']}', '{$quality}', '{$status}'{$extraValues})");
		}
		return (int)$this->conn->insert_id;
	}

	private function rejected(string $reason, string $message, array $extra = []): array
	{
		$outcome = in_array($reason, ['DUPLICATE_BLOCKED', 'FACE_ALREADY_ASSIGNED'], true) ? 'CONFIRMED_DUPLICATE'
			: (in_array($reason, ['ADMIN_REVIEW', 'POSSIBLE_DUPLICATE'], true) ? 'POSSIBLE_DUPLICATE_REVIEW' : 'INSUFFICIENT_EVIDENCE');
		return array_merge(['state' => 'REJECTED', 'outcome' => $outcome, 'reason' => $reason, 'error' => 1, 'message' => $message], $extra);
	}

	private function lockName(int $memberId): string
	{
		return 'face_enrollment_' . substr(hash('sha256', (string)$memberId), 0, 48);
	}

	private function identityLockName(int $branchId, int $modelVersionId): string
	{
		return 'face_enroll_identity_' . substr(hash('sha256', $branchId . ':' . $modelVersionId), 0, 40);
	}

	private function acquireLock(string $lockName): void
	{
		$row = $this->one("SELECT GET_LOCK('{$this->escape($lockName)}', 10) AS acquired");
		if (!$row || (int)$row['acquired'] !== 1) {
			throw new RuntimeException('Could not acquire face enrollment lock');
		}
	}

	private function releaseLock(string $lockName): void
	{
		try {
			$this->run("SELECT RELEASE_LOCK('{$this->escape($lockName)}')");
		} catch (Throwable $ignored) {
			// Best effort after the transaction result is known.
		}
	}

	private function scalar(string $sql)
	{
		$row = $this->one($sql);
		return $row ? reset($row) : null;
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

	/** Return the currently approved inference duplicate policy, or null. */
	private function activeDuplicatePolicy(?string $expectedPolicyVersion = null, ?int $expectedModelVersionId = null): ?array
	{
		if (!is_object($this->inference) || !is_callable([$this->inference, 'identityPolicy'])) return null;
		try {
			$policy = $this->inference->identityPolicy();
		} catch (Throwable $e) {
			return null;
		}
		if (!is_array($policy)) return null;
		$version = (string)($policy['policy_version'] ?? '');
		$review = $policy['review_threshold'] ?? null;
		$block = $policy['duplicate_block_threshold'] ?? null;
		$support = $policy['min_template_support'] ?? null;
		$margin = $policy['min_margin'] ?? null;
		$runtimeModelVersionId = (int)($policy['model_version_id'] ?? 0);
		if ($version === '' || ($expectedPolicyVersion !== null && !hash_equals($expectedPolicyVersion, $version))
			|| ($expectedModelVersionId !== null && $runtimeModelVersionId > 0 && $runtimeModelVersionId !== $expectedModelVersionId)
			|| !is_numeric($review) || !is_numeric($block) || !is_numeric($support) || !is_numeric($margin)) return null;
		$review = (float)$review;
		$block = (float)$block;
		$support = (int)$support;
		$margin = (float)$margin;
		if ($review < 0.0 || $block < $review || $block > 1.0 || $support < 2 || $margin < 0.0 || $margin > 1.0) return null;
		return ['policy_version'=>$version, 'review_threshold'=>$review,
			'duplicate_block_threshold'=>$block, 'min_template_support'=>$support, 'min_margin'=>$margin,
			'model_version_id'=>$runtimeModelVersionId,
			'model_version'=>(string)($policy['model_version'] ?? ''),
			'recognition_generation'=>(string)($policy['recognition_generation'] ?? '')];
	}

	public function checkPreviewCollision(string $frame, int $branchId, int $contactId, ?array $captureContext = null, ?array $captureCommand = null): array
	{
		if (!is_object($this->inference) || !method_exists($this->inference, 'inferEvidence')) {
			return [
				'capture_state' => 'UNAVAILABLE',
				'reason' => 'INFERENCE_UNAVAILABLE',
				'duplicate_band' => 'UNAVAILABLE',
				'reference_match_state' => 'UNCHECKED',
			];
		}
		$modelVersionId = (int)(FaceAttendanceConfig::getEnv('FACE_MODEL_VERSION_ID') ?: '1');
		$activePolicy = $this->activeDuplicatePolicy();
		if ($activePolicy === null || (isset($activePolicy['model_version_id']) && (int)$activePolicy['model_version_id'] > 0
			&& (int)$activePolicy['model_version_id'] !== $modelVersionId)) {
			return [
				'capture_state' => 'UNAVAILABLE',
				'reason' => 'IDENTITY_POLICY_UNAVAILABLE',
				'duplicate_band' => 'UNAVAILABLE',
				'reference_match_state' => 'UNCHECKED',
			];
		}
		if ($contactId < 1) {
			return [
				'capture_state' => 'UNAVAILABLE',
				'reason' => 'CONTACT_REQUIRED',
				'duplicate_band' => 'UNAVAILABLE',
				'reference_match_state' => 'NO_REFERENCE',
			];
		}
		$currentSubjectId = 0;
		$subRow = $this->one("SELECT id FROM biometric_subject WHERE contact_id = '{$contactId}' LIMIT 1");
		if ($subRow) {
			$currentSubjectId = (int)$subRow['id'];
		}

		$cacheKey = null;
		if ($captureCommand && !empty($captureCommand['capture_session_token'])) {
			$session = $this->captureSession($captureCommand);
			if ((int)$session['subject_id'] !== $currentSubjectId) throw new FaceEnrollmentException('STALE_CAPTURE_SESSION', 'Capture member does not match the selected child');
			$reference = $this->getSessionPendingReference((int)$session['id'], false, true);
			$cacheKey = hash_hmac('sha256', (string)$session['id'] . ':' . $modelVersionId . ':' . $contactId, (string)$captureCommand['capture_session_token']);
		} else {
			$reference = $this->getActiveReference($contactId, false);
		}
		$indexStarted = microtime(true);
		$cachedIndex = $cacheKey === null ? null : $this->readPreviewIndexCache($cacheKey);
		$this->lastPreviewIndexCacheHit = $cachedIndex !== null;
		if ($cachedIndex !== null) {
			$candidates = $cachedIndex['candidates'];
			$names = $cachedIndex['names'];
		} else {

		$result = $this->conn->query(
			"SELECT ft.id, ft.subject_id, ft.member_id, ft.encrypted_embedding, ft.embedding_dimension, " .
			"COALESCE(bs.contact_id, m.contact_id) AS contact_id, TRIM(CONCAT(c.fname, ' ', c.lname)) AS fullname " .
			"FROM face_template ft " .
			"LEFT JOIN biometric_subject bs ON bs.id = ft.subject_id " .
			"LEFT JOIN member m ON m.id = ft.member_id " .
			"LEFT JOIN contact c ON c.id = COALESCE(bs.contact_id, m.contact_id) " .
			"WHERE ft.status = 'ACTIVE' " .
			"AND ft.model_version_id = '{$modelVersionId}' " .
			($currentSubjectId > 0 ? "AND (ft.subject_id IS NULL OR ft.subject_id <> '{$currentSubjectId}') " : "") .
			"AND (COALESCE(bs.contact_id, m.contact_id) IS NULL OR COALESCE(bs.contact_id, m.contact_id) <> '{$contactId}') " .
			"ORDER BY ft.id"
		);
		if (!$result) {
			return [
				'capture_state' => 'UNAVAILABLE',
				'reason' => 'INDEX_UNAVAILABLE',
				'duplicate_band' => 'UNAVAILABLE',
				'reference_match_state' => $reference ? 'UNCHECKED' : ($contactId > 0 ? 'NO_REFERENCE' : 'UNCHECKED'),
			];
		}

		$candidates = [];
		$names = [];
		while ($row = $result->fetch_assoc()) {
			try {
				$embedding = $this->cipher->decrypt((string)$row['encrypted_embedding']);
				if (is_array($embedding) && count($embedding) === (int)$row['embedding_dimension']) {
					$identityKey = (int)$row['contact_id'] > 0 ? 'c_' . $row['contact_id'] : 'm_' . $row['member_id'];
					if (!isset($candidates[$identityKey])) {
						$candidates[$identityKey] = ['candidate_ref' => $identityKey, 'embeddings' => []];
						$names[$identityKey] = [
							'member_id' => (int)$row['member_id'],
							'contact_id' => (int)$row['contact_id'],
							'fullname' => !empty($row['fullname']) ? $row['fullname'] : "Student #{$row['member_id']}",
							'has_enrolled_templates' => true,
						];
					}
					$candidates[$identityKey]['embeddings'][] = $embedding;
				} else {
					return [
						'capture_state' => 'UNAVAILABLE',
						'reason' => 'IDENTITY_INDEX_UNAVAILABLE',
						'duplicate_band' => 'UNAVAILABLE',
						'reference_match_state' => $reference ? 'UNCHECKED' : ($contactId > 0 ? 'NO_REFERENCE' : 'UNCHECKED'),
					];
				}
			} catch (Throwable $e) {
				return [
					'capture_state' => 'UNAVAILABLE',
					'reason' => 'IDENTITY_INDEX_UNAVAILABLE',
					'duplicate_band' => 'UNAVAILABLE',
					'reference_match_state' => $reference ? 'UNCHECKED' : ($contactId > 0 ? 'NO_REFERENCE' : 'UNCHECKED'),
				];
			}
		}

		// Also check active references from other subjects
		$refCheck = $this->conn->query("SHOW TABLES LIKE 'face_identity_reference'");
		if ($refCheck && $refCheck->num_rows > 0) {
			$refResult = $this->conn->query(
				"SELECT fir.id, fir.subject_id, fir.encrypted_embedding, fir.embedding_dimension, " .
				"bs.contact_id, TRIM(CONCAT(c.fname, ' ', c.lname)) AS fullname " .
				"FROM face_identity_reference fir " .
				"JOIN biometric_subject bs ON bs.id = fir.subject_id " .
				"JOIN contact c ON c.id = bs.contact_id " .
				"WHERE fir.status = 'ACTIVE' " .
				"AND fir.model_version_id = '{$modelVersionId}' " .
				"AND bs.contact_id <> '{$contactId}' " .
				($currentSubjectId > 0 ? "AND fir.subject_id <> '{$currentSubjectId}' " : "") .
				"ORDER BY fir.id"
			);
			if ($refResult) {
				while ($row = $refResult->fetch_assoc()) {
					try {
						$embedding = $this->cipher->decrypt((string)$row['encrypted_embedding']);
						if (is_array($embedding) && count($embedding) === (int)$row['embedding_dimension']) {
							$identityKey = 'c_' . $row['contact_id'];
							// References aid a warning for an as-yet unenrolled child, but
							// are deliberately not mixed into template support.  A lone
							// reference can never satisfy the duplicate block evidence.
							if (!isset($candidates[$identityKey])) {
								$candidates[$identityKey] = ['candidate_ref' => $identityKey, 'embeddings' => [$embedding]];
								$names[$identityKey] = [
									'member_id' => 0,
									'contact_id' => (int)$row['contact_id'],
									'fullname' => !empty($row['fullname']) ? $row['fullname'] : "Student (Contact #{$row['contact_id']})",
									'has_enrolled_templates' => false,
								];
							}
						} else {
							return [
								'capture_state' => 'UNAVAILABLE',
								'reason' => 'IDENTITY_INDEX_UNAVAILABLE',
								'duplicate_band' => 'UNAVAILABLE',
								'reference_match_state' => $reference ? 'UNCHECKED' : ($contactId > 0 ? 'NO_REFERENCE' : 'UNCHECKED'),
							];
						}
					} catch (Throwable $e) {
						return [
							'capture_state' => 'UNAVAILABLE',
							'reason' => 'IDENTITY_INDEX_UNAVAILABLE',
							'duplicate_band' => 'UNAVAILABLE',
							'reference_match_state' => $reference ? 'UNCHECKED' : ($contactId > 0 ? 'NO_REFERENCE' : 'UNCHECKED'),
						];
					}
				}
			}
		}
		if ($cacheKey !== null) $this->writePreviewIndexCache($cacheKey, array_values($candidates), $names);
		}
		$this->lastPreviewIndexMs = round((microtime(true) - $indexStarted) * 1000, 1);

		try {
			$method = new ReflectionMethod($this->inference, 'inferEvidence');
			$inferResult = ($captureContext && $method->getNumberOfParameters() >= 3)
				? $this->inference->inferEvidence($frame, $candidates, $captureContext)
				: $this->inference->inferEvidence($frame, $candidates);
			$this->lastPreviewInference = $inferResult;
			if (!isset($inferResult['selected_face_index']) || $inferResult['selected_face_index'] === null) {
				return [
					'capture_state' => 'UNAVAILABLE',
					'reason' => 'NO_FACE_DETECTED',
					'duplicate_band' => 'UNAVAILABLE',
					'reference_match_state' => $reference ? 'UNCHECKED' : ($contactId > 0 ? 'NO_REFERENCE' : 'UNCHECKED'),
				];
			}

			$referenceMatchState = 'UNCHECKED';
			$refScore = 0.0;
			if ($reference && !empty($inferResult['embedding']) && is_array($inferResult['embedding'])) {
				$refScore = $this->cosine($inferResult['embedding'], $reference['embedding']);
				$referenceMatchState = ($refScore >= 0.90) ? 'MATCH' : 'MISMATCH';
			} elseif (!$reference && $contactId > 0) {
				$referenceMatchState = 'NO_REFERENCE';
			}

			$topScore = -1.0;
			$matched = null;
			if (!empty($inferResult['top_match']) && isset($inferResult['top_match']['score'])) {
				$topScore = (float)$inferResult['top_match']['score'];
				$matched = $names[$inferResult['top_match']['candidate_ref']] ?? null;
			}
			$identityCandidate = [
				'candidate_member_id' => $matched ? (int)$matched['member_id'] : 0,
				'candidate_contact_id' => $matched ? (int)$matched['contact_id'] : 0,
				'candidate_member_name' => $matched ? $matched['fullname'] : '',
				'has_enrolled_templates' => $matched ? !empty($matched['has_enrolled_templates']) : false,
				'margin' => isset($inferResult['margin']) && is_numeric($inferResult['margin'])
					? round((float)$inferResult['margin'], 4) : null,
			];
			$reviewThreshold = $activePolicy['review_threshold'];
			$blockThreshold = $activePolicy['duplicate_block_threshold'];
			$templateSupport = (int)($inferResult['top_match']['support'] ?? 0);

			if ($topScore >= $blockThreshold) {
				return [
					'capture_state' => 'CONFLICT',
					'duplicate_band' => 'BLOCKED',
					'reference_match_state' => $referenceMatchState,
					'conflicting_member_id' => $matched ? $matched['member_id'] : 0,
					'conflicting_contact_id' => $matched ? $matched['contact_id'] : 0,
					'conflicting_member_name' => $matched ? $matched['fullname'] : 'Registered Student',
					'template_support' => $templateSupport,
				] + $identityCandidate + [
					'score' => round($topScore, 4),
					'reference_score' => round($refScore, 4),
				];
			}

			if ($topScore >= $reviewThreshold) {
				return [
					'capture_state' => 'CONFLICT',
					'duplicate_band' => 'REVIEW',
					'reference_match_state' => $referenceMatchState,
					'conflicting_member_id' => $matched ? $matched['member_id'] : 0,
					'conflicting_contact_id' => $matched ? $matched['contact_id'] : 0,
					'conflicting_member_name' => $matched ? $matched['fullname'] : 'Registered Student',
					'template_support' => $templateSupport,
				] + $identityCandidate + [
					'score' => round($topScore, 4),
					'reference_score' => round($refScore, 4),
				];
			}

			if ($referenceMatchState === 'MISMATCH') {
				return [
					'capture_state' => 'CONFLICT',
					'duplicate_band' => 'CLEAR',
					'reference_match_state' => 'MISMATCH',
					'reason' => 'REFERENCE_MISMATCH',
					'conflicting_member_name' => '',
					'template_support' => $templateSupport,
				] + $identityCandidate + [
					'score' => max(0.0, round($topScore, 4)),
					'reference_score' => round($refScore, 4),
				];
			}

			return [
				'capture_state' => 'CLEAR',
				'duplicate_band' => 'CLEAR',
				'reference_match_state' => $referenceMatchState,
				'template_support' => $templateSupport,
			] + $identityCandidate + [
				'score' => max(0.0, round($topScore, 4)),
				'reference_score' => round($refScore, 4),
			];
		} catch (Throwable $e) {
			return [
				'capture_state' => 'UNAVAILABLE',
				'reason' => 'INFERENCE_FAILED',
				'duplicate_band' => 'UNAVAILABLE',
				'reference_match_state' => $reference ? 'UNCHECKED' : ($contactId > 0 ? 'NO_REFERENCE' : 'UNCHECKED'),
			];
		}
	}

	private $lastPreviewInference = null;
	private $lastPreviewIndexMs = null;
	private $lastPreviewIndexCacheHit = false;

	/** Decide from two distinct frontal captures without persisting diagnostic evidence. */
	public function checkEarlyEnrollmentIdentity(array $command): array
	{
		$policy = $this->activeDuplicatePolicy();
		if ($policy === null) return ['state'=>'CHECK_UNAVAILABLE', 'reason_code'=>'IDENTITY_POLICY_UNAVAILABLE'];
		$frames = $command['frames'] ?? null;
		$contexts = $command['capture_contexts'] ?? null;
		if (!is_array($frames) || count($frames) !== 2 || !is_array($contexts) || count($contexts) !== 2
			|| !is_array($contexts[0]) || !is_array($contexts[1])
			|| !is_string($frames[0]) || !is_string($frames[1]) || $frames[0] === '' || $frames[1] === ''
			|| strlen($frames[0]) > $this->maxFrameBytes || strlen($frames[1]) > $this->maxFrameBytes
			|| hash('sha256', $frames[0]) === hash('sha256', $frames[1])) {
			return ['state'=>'CHECK_UNAVAILABLE', 'reason_code'=>'INVALID_EARLY_EVIDENCE'];
		}
		$contactId = (int)($command['contact_id'] ?? 0);
		$branchId = (int)($command['branch_id'] ?? 0);
		if ($contactId < 1 || empty($command['capture_session_token'])) {
			return ['state'=>'CHECK_UNAVAILABLE', 'reason_code'=>'STALE_CAPTURE_SESSION'];
		}
		// Validate the selected subject through the same capture-session gate as
		// candidate checks. Each call excludes that subject from the active index.
		$observations = [];
		$unavailable = false;
		foreach ($frames as $index => $frame) {
			$this->lastPreviewInference = null;
			$result = $this->checkPreviewCollision($frame, $branchId, $contactId, $contexts[$index], $command);
			$inference = $this->lastPreviewInference;
			$face = is_array($inference) && isset($inference['selected_face_index'])
				? ($inference['faces'][$inference['selected_face_index']] ?? null) : null;
			$quality = is_array($face) ? ($face['quality'] ?? []) : [];
			$yaw = $quality['pose_yaw'] ?? null;
			$pitch = $quality['pose_pitch'] ?? null;
			if (($result['duplicate_band'] ?? '') === 'UNAVAILABLE' || !is_array($quality)
				|| ($quality['accepted'] ?? false) !== true || !is_numeric($yaw) || !is_numeric($pitch)
				|| !is_finite((float)$yaw) || !is_finite((float)$pitch)
				|| abs((float)$yaw) > 0.06 || (float)$pitch < 0.35 || (float)$pitch > 0.75) {
				$unavailable = true;
				continue;
			}
			$observations[] = $result;
		}
		$identityKey = static function (array $result): string {
			$contactId = (int)($result['candidate_contact_id'] ?? $result['conflicting_contact_id'] ?? 0);
			$memberId = (int)($result['candidate_member_id'] ?? $result['conflicting_member_id'] ?? 0);
			if ($contactId > 0) return 'c_' . $contactId;
			if ($memberId > 0) return 'm_' . $memberId;
			return '';
		};
		$strongEvidence = function (array $result) use ($identityKey, $policy): bool {
			return $identityKey($result) !== ''
				&& !empty($result['has_enrolled_templates'])
				&& is_numeric($result['score'] ?? null)
				&& (float)$result['score'] >= $policy['duplicate_block_threshold']
				&& (int)($result['template_support'] ?? 0) >= $policy['min_template_support']
				&& (!isset($result['margin']) || $result['margin'] === null
					|| (is_numeric($result['margin']) && (float)$result['margin'] >= $policy['min_margin']));
		};
		$first = $observations[0] ?? [];
		$second = $observations[1] ?? [];
		$confirmed = count($observations) === 2 && $strongEvidence($first) && $strongEvidence($second)
			&& $identityKey($first) !== '' && $identityKey($first) === $identityKey($second);
		if ($confirmed) {
			return ['state'=>'CONFIRMED_DUPLICATE', 'reason_code'=>'DUPLICATE_BLOCKED', 'policy_version'=>$policy['policy_version'],
				'conflicting_member_id'=>$second['conflicting_member_id'] ?? 0,
				'conflicting_member_name'=>$second['conflicting_member_name'] ?? 'another student'];
		}
		// A partial pair is never trustworthy enough to assert a duplicate or
		// to turn an otherwise advisory match into a decision.
		if ($unavailable) return ['state'=>'CHECK_UNAVAILABLE', 'reason_code'=>'EARLY_EVIDENCE_UNAVAILABLE', 'policy_version'=>$policy['policy_version']];
		foreach ($observations as $result) {
			if ($strongEvidence($result) || in_array($result['duplicate_band'] ?? '', ['BLOCKED','REVIEW'], true)) {
				return ['state'=>'POSSIBLE_SIMILARITY', 'reason_code'=>'POSSIBLE_DUPLICATE', 'policy_version'=>$policy['policy_version'],
					'conflicting_member_id'=>$result['candidate_member_id'] ?? $result['conflicting_member_id'] ?? 0,
					'conflicting_member_name'=>$result['candidate_member_name'] ?? $result['conflicting_member_name'] ?? 'another student'];
			}
		}
		return ['state'=>'CLEAR', 'reason_code'=>'IDENTITY_CLEAR', 'policy_version'=>$policy['policy_version']];
	}

	public function checkEnrollmentCandidate(array $command): array
	{
		$started = microtime(true);
		$slot = filter_var($command['slot'] ?? null, FILTER_VALIDATE_INT);
		if ($slot === false || $slot < 0 || $slot > 9 || ($command['pose_protocol'] ?? '') !== self::GUIDED_ADAPTIVE_PROTOCOL)
			return ['state'=>'REJECTED','accepted'=>false,'reason_code'=>'INVALID_CANDIDATE_SLOT'];
		$frame = $command['frame'] ?? null;
		if (!is_string($frame) || $frame === '' || strlen($frame) > $this->maxFrameBytes)
			return ['state'=>'REJECTED','accepted'=>false,'reason_code'=>'INVALID_FRAME'];
		if (empty($command['capture_session_token']) || (int)($command['contact_id'] ?? 0) < 1)
			return ['state'=>'REJECTED','accepted'=>false,'reason_code'=>'STALE_CAPTURE_SESSION'];
		if (!isset($command['capture_context']) || !is_array($command['capture_context']))
			return ['state'=>'REJECTED','accepted'=>false,'reason_code'=>'INVALID_CAPTURE_CONTEXT'];
		if ($slot >= 8) {
			$this->captureSession($command);
			if (!is_callable([$this->inference, 'previewEnrollment']))
				return ['state'=>'REJECTED','accepted'=>false,'reason_code'=>'INFERENCE_UNAVAILABLE'];
			$preview = $this->inference->previewEnrollment($frame, $command['capture_context']);
			$empty = ($preview['face_count'] ?? -1) === 0 && in_array($preview['reason_code'] ?? '', ['NO_FACE','NO_FACE_IN_GUIDE'], true);
			$timestamp = (string)(int)floor(microtime(true) * 1000);
			$hash = hash('sha256', $frame);
			return ['state'=>$empty ? 'ACCEPTED' : 'REJECTED','accepted'=>$empty,
				'reason_code'=>$empty ? 'DEPARTURE_ACCEPTED' : 'FACE_STILL_PRESENT',
				'sha256'=>$hash,'timestamp_ms'=>(int)$timestamp,
				'candidate_token'=>$empty ? hash_hmac('sha256', $slot . ':' . $hash . ':' . $timestamp,
					$this->candidateSigningKey((string)$command['capture_session_token'])) : null,
				'timings_ms'=>['total'=>round((microtime(true)-$started)*1000, 1),
					'inference'=>$preview['latency_ms'] ?? null]];
		}
		$this->lastPreviewInference = null;
		$this->lastPreviewIndexMs = null;
		$this->lastPreviewIndexCacheHit = false;
		$collision = $this->checkPreviewCollision($frame, (int)$command['branch_id'], (int)$command['contact_id'],
			isset($command['capture_context']) && is_array($command['capture_context']) ? $command['capture_context'] : null,
			$command);
		$inference = $this->lastPreviewInference;
		$face = is_array($inference) && isset($inference['selected_face_index'])
			? ($inference['faces'][$inference['selected_face_index']] ?? null) : null;
		$quality = is_array($face) ? ($face['quality'] ?? []) : [];
		$yaw = $quality['pose_yaw'] ?? null;
		$pitch = $quality['pose_pitch'] ?? null;
		$reason = $collision['reason'] ?? ($inference['reason_code'] ?? 'INFERENCE_UNAVAILABLE');
		if (!is_array($inference) || !isset($inference['selected_face_index']) || $inference['selected_face_index'] === null)
			$reason = $inference['reason_code'] ?? ($collision['reason'] ?? 'NO_FACE_DETECTED');
		elseif (($collision['capture_state'] ?? '') === 'UNAVAILABLE') $reason = $collision['reason'] ?? 'INFERENCE_UNAVAILABLE';
		// A candidate is one observation. Keep similarity visible to the client;
		// the paired frontal check or final two-burst check makes the decision.
		elseif (($collision['reference_match_state'] ?? '') === 'MISMATCH') $reason = 'REFERENCE_MISMATCH';
		elseif (($collision['reference_match_state'] ?? '') !== 'MATCH') $reason = 'REFERENCE_UNAVAILABLE';
		elseif (!is_array($quality) || ($quality['accepted'] ?? false) !== true)
			$reason = $quality['reasons'][0] ?? 'QUALITY_REJECTED';
		elseif ((!is_float($yaw) && !is_int($yaw)) || (!is_float($pitch) && !is_int($pitch))
			|| !is_finite((float)$yaw) || !is_finite((float)$pitch)) $reason = 'POSE_MEASUREMENTS_MISSING';
		else {
			$centre = $slot < 2 || $slot >= 5;
			$signedYaw = $slot === 2 ? -$yaw : ($slot === 3 ? $yaw : abs($yaw));
			$poseValid = $centre
				? (abs($yaw) <= 0.06 && $pitch >= 0.35 && $pitch <= 0.75)
				: ($signedYaw >= 0.08 && $signedYaw <= 0.16 && $pitch >= 0.27 && $pitch <= 0.83);
			$reason = $poseValid ? 'CANDIDATE_ACCEPTED' : 'POSE_TARGET_MISMATCH';
		}
		$accepted = $reason === 'CANDIDATE_ACCEPTED';
		$hash = hash('sha256', $frame);
		$contextHash = hash('sha256', json_encode($command['capture_context'], JSON_UNESCAPED_SLASHES));
		$timestamp = (string)(int)floor(microtime(true) * 1000);
		$token = $accepted ? $timestamp . ':' . hash_hmac('sha256', $slot . ':' . $hash . ':' . $contextHash . ':' . $timestamp,
			$this->candidateSigningKey((string)$command['capture_session_token'])) : null;
		$totalMs = round((microtime(true)-$started)*1000, 1);
		FaceErrorLogger::record('FACE_ENROLLMENT_CANDIDATE', [
			'slot'=>$slot, 'yaw'=>is_numeric($yaw) ? round((float)$yaw, 3) : null,
			'run_id'=>substr((string)($command['run_id'] ?? ''), 0, 64),
			'camera_label'=>substr((string)($command['camera_label'] ?? 'unavailable'), 0, 160),
			'browser'=>substr((string)($command['browser'] ?? 'unknown'), 0, 40),
			'user_agent'=>substr((string)($command['user_agent'] ?? 'unknown'), 0, 300),
			'pitch'=>is_numeric($pitch) ? round((float)$pitch, 3) : null,
			'browser_yaw'=>isset($command['browser_yaw']) && is_numeric($command['browser_yaw']) && is_finite((float)$command['browser_yaw'])
				? round((float)$command['browser_yaw'], 3) : null,
			'browser_matrix_yaw'=>isset($command['browser_matrix_yaw']) && is_numeric($command['browser_matrix_yaw']) && is_finite((float)$command['browser_matrix_yaw'])
				? round((float)$command['browser_matrix_yaw'], 3) : null,
			'browser_observation_age_ms'=>isset($command['browser_observation_age_ms']) && is_numeric($command['browser_observation_age_ms'])
				? max(0, min(10000, (int)$command['browser_observation_age_ms'])) : null,
			'browser_facing_mode'=>in_array($command['browser_facing_mode'] ?? '', ['user','environment'], true)
				? $command['browser_facing_mode'] : null,
			'reference_score'=>$collision['reference_score'] ?? null,
			'reason_code'=>$reason, 'duplicate_band'=>$collision['duplicate_band'] ?? 'UNAVAILABLE',
			'index_ms'=>$this->lastPreviewIndexMs, 'index_cache_hit'=>$this->lastPreviewIndexCacheHit,
			'inference_ms'=>$inference['latency_ms'] ?? null, 'total_ms'=>$totalMs]);
		return ['state'=>$accepted ? 'ACCEPTED' : 'REJECTED','accepted'=>$accepted,
			'reason_code'=>$reason,'sha256'=>$hash,'candidate_token'=>$token,
			'yaw'=>is_numeric($yaw) ? round((float)$yaw, 3) : null,
			'pitch'=>is_numeric($pitch) ? round((float)$pitch, 3) : null,
			'reference_score'=>$collision['reference_score'] ?? null,
			'duplicate_band'=>$collision['duplicate_band'] ?? 'UNAVAILABLE',
			'timings_ms'=>['total'=>$totalMs,
				'inference'=>$inference['latency_ms'] ?? null,
				'index'=>$this->lastPreviewIndexMs],
			'index_cache_hit'=>$this->lastPreviewIndexCacheHit];
	}

	private function candidateSigningKey(string $captureToken): string
	{
		return hash_hmac('sha256', 'guided-adaptive-v2:' . $captureToken,
			FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY'), true);
	}

	private function verifyAdaptiveCaptureEvidence(array $command, array $burstA, array $burstB): ?string
	{
		$tokens = $command['candidate_tokens'] ?? null;
		$departure = $command['departure_tokens'] ?? null;
		if (!is_array($tokens) || count($tokens) !== 8 || empty($command['capture_session_token'])) return 'CANDIDATE_CHECK_REQUIRED';
		$legacyDeparture = $departure !== null;
		if ($legacyDeparture && (!is_array($departure) || count($departure) !== 2)) return 'DEPARTURE_CHECK_REQUIRED';
		$contextsA = $command['capture_contexts_a'] ?? null;
		$contextsB = $command['capture_contexts_b'] ?? null;
		if (!is_array($contextsA) || count($contextsA) !== 5 || !is_array($contextsB) || count($contextsB) !== 3)
			return 'INVALID_CAPTURE_CONTEXT';
		$contexts = array_merge($contextsA, $contextsB);
		$key = $this->candidateSigningKey((string)$command['capture_session_token']);
		$times = [];
		foreach (array_merge($burstA, $burstB) as $i => $frame) {
			if (!is_array($contexts[$i] ?? null)) return 'INVALID_CAPTURE_CONTEXT';
			$parts = is_string($tokens[$i] ?? null) ? explode(':', $tokens[$i], 2) : [];
			$timestamp = $parts[0] ?? '';
			$contextHash = hash('sha256', json_encode($contexts[$i], JSON_UNESCAPED_SLASHES));
			$expected = hash_hmac('sha256', $i . ':' . hash('sha256', $frame) . ':' . $contextHash . ':' . $timestamp, $key);
			if (!preg_match('/^[0-9]{13}$/', $timestamp) || !hash_equals($expected, $parts[1] ?? '')) return 'CANDIDATE_BYTES_CHANGED';
			$times[$i] = (int)$timestamp;
		}
		$departureTimes = [];
		if ($legacyDeparture) {
			foreach ([8,9] as $j => $slot) {
				$entry = $departure[$j] ?? null;
				$timestamp = is_array($entry) ? (string)($entry['timestamp_ms'] ?? '') : '';
				$hash = is_array($entry) ? (string)($entry['sha256'] ?? '') : '';
				$token = is_array($entry) ? (string)($entry['token'] ?? '') : '';
				$expected = hash_hmac('sha256', $slot . ':' . $hash . ':' . $timestamp, $key);
				if (!preg_match('/^[0-9]{13}$/', $timestamp) || !preg_match('/^[a-f0-9]{64}$/', $hash)
					|| !hash_equals($expected, $token)) return 'DEPARTURE_CHECK_INVALID';
				$departureTimes[$j] = (int)$timestamp;
			}
		}
		$ordered = $times[0] < $times[1] && $times[1] < $times[2] && $times[2] < $times[3]
			&& $times[3] < $times[4] && $times[5] < $times[6] && $times[6] < $times[7];
		$separated = $legacyDeparture
			? $times[4] < $departureTimes[0] && $departureTimes[1] - $departureTimes[0] >= 750
				&& $departureTimes[1] < $times[5]
			: $times[5] - $times[4] >= 750;
		if (!$ordered || !$separated)
			return 'CAPTURE_SEQUENCE_INVALID';
		return null;
	}

	/**
	 * Preview identity indexes contain biometric embeddings.  They intentionally
	 * have no disk cache: retry performance must not create a second persisted
	 * biometric store outside the enrolled-template tables.
	 */
	private function readPreviewIndexCache(string $cacheKey): ?array
	{
		return null;
	}

	private function writePreviewIndexCache(string $cacheKey, array $candidates, array $names): void
	{
		// Deliberately no-op; see readPreviewIndexCache().
	}
}
