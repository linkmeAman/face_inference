<?php

require_once dirname(__DIR__) . '/classes/dbconnection.class.php';
require_once dirname(__DIR__) . '/classes/session.class.php';
require_once dirname(__DIR__) . '/classes/services/FaceEnrollmentService.php';
require_once dirname(__DIR__) . '/classes/services/FaceErrorLogger.php';
require_once dirname(__DIR__) . '/classes/clients/FaceInferenceClient.php';
require_once dirname(__DIR__) . '/classes/providers/FaceRecognitionProviderFactory.php';
require_once dirname(__DIR__) . '/classes/services/FaceAttendanceConfig.php';
require_once dirname(__DIR__) . '/classes/services/BrowserFacePolicy.php';
require_once dirname(__DIR__) . '/inc/defaults.php';

class faceEnrollment extends dbconnection
{
	public function handle(string $action, array $request): string
	{
		if ($action === 'getReferencePortrait') {
			$this->streamReferencePortrait($request);
			exit;
		}
		foreach (['capture_context', 'capture_contexts', 'capture_contexts_a', 'capture_contexts_b'] as $geometryField) {
			if (array_key_exists($geometryField, $request) && is_string($request[$geometryField])) {
				$decodedGeometry = json_decode($request[$geometryField], true);
				if (!is_array($decodedGeometry)) {
					return $this->invalidCaptureContext('Capture geometry is invalid');
				}
				$request[$geometryField] = $decodedGeometry;
			}
			if (array_key_exists($geometryField, $request) && !is_array($request[$geometryField])) {
				return $this->invalidCaptureContext('Capture geometry is invalid');
			}
			if ($geometryField !== 'capture_context' && array_key_exists($geometryField, $request)) {
				foreach ($request[$geometryField] as $context) {
					if (!is_array($context)) {
						return $this->invalidCaptureContext('Capture geometry entries must be objects');
					}
				}
			}
		}
		if (isset($request['candidate_tokens']) && is_string($request['candidate_tokens'])) {
			$request['candidate_tokens'] = json_decode($request['candidate_tokens'], true);
		}
		if (isset($request['departure_tokens']) && is_string($request['departure_tokens'])) {
			$request['departure_tokens'] = json_decode($request['departure_tokens'], true);
		}
		$contextFramePairs = [
			'capture_contexts' => $action === 'onboardReference' ? 'portraits' : 'frames',
			'capture_contexts_a' => 'burst_a',
			'capture_contexts_b' => 'burst_b',
		];
		foreach ($contextFramePairs as $contextField => $frameField) {
			if (array_key_exists($contextField, $request)
				&& (!isset($request[$frameField]) || !is_array($request[$frameField]) || count($request[$contextField]) !== count($request[$frameField]))) {
				return $this->invalidCaptureContext('Capture geometry must match every submitted frame');
			}
		}
		$branchId = (int)($request['branch_id'] ?? 0);
		if (!FaceAttendanceConfig::getFlag('FACE_ENROLLMENT_ENABLED', null, $branchId)) {
			return $this->json(['state' => 'REJECTED', 'reason' => 'FEATURE_DISABLED', 'error' => 1, 'message' => 'Face enrollment is disabled']);
		}
		$session = new session;
		$dbName = $session->check_session($request);
		$actorId = (int)(get_uid() ?: 0);
		$branchId = (int)($request['branch_id'] ?? 0);
		if (!$dbName || $actorId < 1 || !$session->access($actorId, $this->module('Attendance'), 1)) {
			return $this->json(['state' => 'REJECTED', 'reason' => 'ATTENDANCE_ACCESS', 'error' => 1, 'message' => 'Attendance permission is required']);
		}
		if ($branchId < 1 || (isset($_SERVER['HTTP_BID']) && $_SERVER['HTTP_BID'] !== '' && (int)$_SERVER['HTTP_BID'] !== $branchId)) {
			return $this->json(['state' => 'REJECTED', 'reason' => 'BRANCH_ACCESS', 'error' => 1, 'message' => 'Selected branch is not authorized']);
		}
		$allowUncalibratedTesting = $this->allowUncalibratedTesting($branchId);
		$key = FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY');
		$keyId = FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY_ID');
		if ($key === '' || $keyId === '') {
			return $this->json(['state' => 'REJECTED', 'reason' => 'FACE_CONFIGURATION', 'error' => 1, 'message' => 'Face enrollment encryption is not configured']);
		}
		try {
			$conn = $this->connect($dbName);
			// Once the browser pipeline is installed, legacy writers must not
			// create/activate templates outside its central duplicate transaction.
			if (in_array($action, ['enrollFace','onboardReference','beginEnrollment','reviewReenrollmentAttempt'], true)
				&& BrowserFacePolicy::installed($conn)) {
				$conn->close();
				return $this->json(['state'=>'REJECTED','reason'=>'AUTOMATIC_WEB_ENROLLMENT_REQUIRED','error'=>1,
					'message'=>'Use automatic web enrollment at /pf/face.php']);
			}
			$provider = FaceRecognitionProviderFactory::create();
			$service = new FaceEnrollmentService($conn, $provider, new FaceEmbeddingCipher($key, $keyId));
			$request['browser_session_id'] = (string)($request['session_id'] ?? '');
			// Attendance roster rows come from invoice items; their `id` is not a
			// member ID. Resolve the member from the selected contact and branch.
			if (!empty($request['contact_id'])) {
				$contactId = (int)$request['contact_id'];
				$memberResult = $conn->query("SELECT id FROM member WHERE contact_id = '{$contactId}' AND bid = '{$branchId}' AND status = '1' AND park = '0' LIMIT 1");
				$memberRow = $memberResult ? $memberResult->fetch_assoc() : null;
				$request['member_id'] = $memberRow ? (int)$memberRow['id'] : 0;
			}
			if (in_array($action, ['beginEnrollment','clearReference'], true)) {
				$request['actor_employee_id'] = $actorId;
				$result = $action === 'beginEnrollment' ? $service->beginEnrollment($request) : $service->clearReference($request);
				$conn->close();
				return $this->json($result);
			}
			if ($action === 'reviewReenrollmentAttempt' || $action === 'listReenrollmentReviews') {
				$central = $this->connect('ccc_central');
				$admin = $central->query("SELECT position_id FROM user WHERE id='{$actorId}' LIMIT 1");
				$position = $admin ? $admin->fetch_assoc() : null;
				$central->close();
				if (!$position || (int)$position['position_id'] !== 1) {
					$conn->close();
					return $this->json(['state'=>'REJECTED','reason'=>'UNAUTHORIZED_ADMIN_OVERRIDE','error'=>1]);
				}
				$result = $action === 'listReenrollmentReviews'
					? $service->listReenrollmentReviews($branchId)
					: $service->reviewReenrollmentAttempt((int)($request['attempt_id'] ?? 0), (string)($request['decision'] ?? ''), $actorId, ($request['in_person_verified'] ?? false) === true, (string)($request['reason'] ?? ''), (int)$position['position_id']);
				$conn->close();
				return $this->json($result);
			}
			if ($action === 'previewEnrollment') {
				$previewStarted = microtime(true);
				if (($request['run_started'] ?? '') === '1') {
					FaceErrorLogger::record('FACE_ENROLLMENT_RUN_STARTED', [
						'run_id'=>substr((string)($request['run_id'] ?? ''), 0, 64),
						'camera_label'=>substr((string)($request['camera_label'] ?? 'unavailable'), 0, 160),
						'browser'=>substr((string)($request['browser'] ?? 'unknown'), 0, 40),
						'user_agent'=>substr((string)($request['user_agent'] ?? 'unknown'), 0, 300),
					]);
				}
				if (empty($request['frame']) || !is_string($request['frame'])) {
					$conn->close();
					return $this->json(['state' => 'REJECTED', 'reason' => 'INVALID_FRAME', 'error' => 1, 'message' => 'Enrollment preview frame is required']);
				}
				$captureContext = isset($request['capture_context']) && is_array($request['capture_context']) ? $request['capture_context'] : null;
				$preview = $provider->previewEnrollment($request['frame'], $captureContext);
				$detectionMs = round((microtime(true) - $previewStarted) * 1000, 1);
				$guidedCapability = FaceAttendanceConfig::getFlag('FACE_GUIDED_ADAPTIVE_V2_ENABLED', null, $branchId)
					? FaceEnrollmentService::guidedEnrollmentCapability($provider, $preview['guided_adaptive'] ?? null) : null;
				$guidedCapability = $guidedCapability
					?: FaceEnrollmentService::guidedEnrollmentCapability($provider, $preview['guided_selection'] ?? null)
					?: FaceEnrollmentService::guidedEnrollmentCapability($provider, $preview['guided_enrollment'] ?? null);
				unset($preview['guided_enrollment'], $preview['guided_selection'], $preview['guided_adaptive']);
				if ($guidedCapability !== null) $preview['guided_enrollment'] = $guidedCapability;
				$policyVersion = FaceAttendanceConfig::getEnv('FACE_CALIBRATION_POLICY_VERSION') ?: 'face-policy-v2';
				$collision = null;
				$possibleDuplicate = null;
				$captureState = 'CLEAR';
				$duplicateBand = 'CLEAR';
				$referenceMatchState = 'UNCHECKED';
				$referenceScore = null;

				$contactId = (int)($request['contact_id'] ?? 0);
				if ($contactId < 1 && !empty($request['member_id'])) {
					$mId = (int)$request['member_id'];
					$mResult = $conn->query("SELECT contact_id FROM member WHERE id = '{$mId}' AND bid = '{$branchId}' LIMIT 1");
					$mRow = $mResult ? $mResult->fetch_assoc() : null;
					$contactId = $mRow ? (int)$mRow['contact_id'] : 0;
				}

				if (isset($preview['selected_face_index']) && $preview['selected_face_index'] !== null && $contactId > 0) {
					$identityStarted = microtime(true);
					$previewCapture = !empty($request['capture_session_token'])
						? array_replace($request, ['branch_id'=>$branchId,'actor_employee_id'=>$actorId]) : null;
					$collisionResult = $service->checkPreviewCollision($request['frame'], $branchId, $contactId, $captureContext, $previewCapture);
					$identityMs = round((microtime(true) - $identityStarted) * 1000, 1);
					$captureState = (($collisionResult['reference_match_state'] ?? '') === 'MISMATCH') ? 'CONFLICT' : 'CLEAR';
					$duplicateBand = $collisionResult['duplicate_band'] ?? 'CLEAR';
					$referenceMatchState = $collisionResult['reference_match_state'] ?? 'UNCHECKED';
					if (isset($collisionResult['reference_score']) && is_numeric($collisionResult['reference_score'])) {
						$referenceScore = round((float)$collisionResult['reference_score'], 4);
					}
					if ($duplicateBand === 'BLOCKED' || $duplicateBand === 'REVIEW' || !empty($collisionResult['conflicting_member_id'])) {
						// A single preview frame is insufficient evidence for a duplicate
						// decision. Surface it as guidance; the independent 5+3 bursts
						// make the authoritative enrollment decision.
						$possibleDuplicate = $collisionResult;
					}
				} elseif (($preview['reason_code'] ?? '') === 'MULTIPLE_FACES_IN_GUIDE') {
					$captureState = 'CONFLICT';
					$duplicateBand = 'UNAVAILABLE';
				} elseif (!isset($preview['selected_face_index']) || $preview['selected_face_index'] === null) {
					$captureState = 'UNAVAILABLE';
					$duplicateBand = 'UNAVAILABLE';
				} elseif ($contactId < 1) {
					$captureState = 'UNAVAILABLE';
					$duplicateBand = 'UNAVAILABLE';
					$referenceMatchState = 'NO_REFERENCE';
				}

				$conn->close();
				return $this->json(array_merge(
					['state' => 'PREVIEW'],
					$preview,
					[
						'capture_state' => $captureState,
						'quality_policy_version' => $policyVersion,
						'duplicate_band' => $duplicateBand,
						'reference_match_state' => $referenceMatchState,
						'reference_score' => $referenceScore,
						'timings_ms' => ['detection'=>$detectionMs, 'identity'=>$identityMs ?? null,
							'total'=>round((microtime(true)-$previewStarted)*1000, 1)],
						'collision' => $collision,
						'possible_duplicate' => $possibleDuplicate,
					]
				));
			}
			if ($action === 'checkEnrollmentCandidate') {
				if (!FaceAttendanceConfig::getFlag('FACE_GUIDED_ADAPTIVE_V2_ENABLED', null, $branchId)) {
					$conn->close();
					return $this->json(['state'=>'REJECTED','accepted'=>false,'reason_code'=>'FEATURE_DISABLED']);
				}
				$request['actor_employee_id'] = $actorId;
				$result = $service->checkEnrollmentCandidate($request);
				$conn->close();
				return $this->json($result);
			}
			if ($action === 'checkEarlyEnrollmentIdentity') {
				$request['actor_employee_id'] = $actorId;
				$result = $service->checkEarlyEnrollmentIdentity($request);
				$conn->close();
				return $this->json($result);
			}
			if ($action === 'onboardReference') {
                if ((int)($request["member_id"] ?? 0) < 1 && !empty($request["contact_id"])) {
					$contactId = (int)$request['contact_id'];
					$memberResult = $conn->query("SELECT id FROM member WHERE contact_id = '{$contactId}' AND bid = '{$branchId}' AND status = '1' AND park = '0' LIMIT 1");
					$memberRow = $memberResult ? $memberResult->fetch_assoc() : null;
					$request['member_id'] = $memberRow ? (int)$memberRow['id'] : 0;
				}
				$request['actor_employee_id'] = $actorId;
				$request['branch_id'] = $branchId;
				$request['allow_uncalibrated_testing'] = $allowUncalibratedTesting;
				$result = $service->onboardReference($request);
				$conn->close();
				return $this->json($result);
			}
			if ($action === 'getReference') {
				$contactId = (int)($request['contact_id'] ?? 0);
				if ($contactId < 1 && !empty($request['member_id'])) {
					$mId = (int)$request['member_id'];
					$mResult = $conn->query("SELECT contact_id FROM member WHERE id = '{$mId}' AND bid = '{$branchId}' LIMIT 1");
					$mRow = $mResult ? $mResult->fetch_assoc() : null;
					$contactId = $mRow ? (int)$mRow['contact_id'] : 0;
				}
				if ($contactId < 1) {
					$conn->close();
					return $this->json(['state' => 'REJECTED', 'reason' => 'INVALID_CONTACT', 'error' => 1, 'message' => 'Valid child contact identifier is required']);
				}
			$ref = $service->getActiveReference($contactId, true, false);
				$conn->close();
				if (!$ref) {
					return $this->json([
						'state' => 'NO_REFERENCE',
						'has_reference' => false,
						'contact_id' => $contactId,
						'reference' => null,
					]);
				}
				return $this->json([
					'state' => 'ACTIVE',
					'has_reference' => true,
					'contact_id' => $contactId,
					'reference' => $ref,
				]);
			}
            if (($action === "enrollFace" || $action === "withdrawFace") && (int)($request["member_id"] ?? 0) < 1 && !empty($request["contact_id"])) {
				$contactId = (int)$request['contact_id'];
				$memberResult = $conn->query("SELECT id FROM member WHERE contact_id = '{$contactId}' AND bid = '{$branchId}' AND status = '1' AND park = '0' LIMIT 1");
				$memberRow = $memberResult ? $memberResult->fetch_assoc() : null;
				$request['member_id'] = $memberRow ? (int)$memberRow['id'] : 0;
			}
			if ($action === 'enrollFace') {
				if (($request['pose_protocol'] ?? '') === FaceEnrollmentService::GUIDED_ADAPTIVE_PROTOCOL
					&& !FaceAttendanceConfig::getFlag('FACE_GUIDED_ADAPTIVE_V2_ENABLED', null, $branchId)) {
					$conn->close();
					return $this->json(['state'=>'REJECTED','reason'=>'FEATURE_DISABLED','error'=>1]);
				}
				if (!filter_var($request['teacher_confirmed_member'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
					$conn->close();
					return $this->json(['state' => 'REJECTED', 'reason' => 'MEMBER_CONFIRMATION_REQUIRED', 'error' => 1, 'message' => 'Teacher must confirm the selected child']);
				}
				if (empty($request['notice_version'])) {
					$request['notice_version'] = 'program-face-testing-v1';
				}
				if (empty($request['purpose'])) {
					$request['purpose'] = 'testing-branch-face-attendance';
				}
				if (empty($request['device_meta']) && empty($request['device_metadata'])) {
					$deviceHeader = $_SERVER['HTTP_DEVICE'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
					if ($deviceHeader !== '') {
						$request['device_meta'] = ['device' => $deviceHeader];
					}
				}
				$request['actor_employee_id'] = $actorId;
				$request['branch_id'] = $branchId;
				$request['allow_uncalibrated_testing'] = $allowUncalibratedTesting;
				$result = $service->enroll($request);
			} elseif ($action === 'withdrawFace') {
				$result = $service->withdraw((int)($request['member_id'] ?? 0), $actorId, $branchId);
			} else {
				$result = ['state' => 'REJECTED', 'reason' => 'BAD_ACTION', 'error' => 1, 'message' => 'Unknown face enrollment action'];
			}
			$conn->close();
			return $this->json($result);
		} catch (Throwable $exception) {
			if ($action === 'checkEnrollmentCandidate') {
				$reason = $exception instanceof FaceEnrollmentException ? $exception->reason
					: ($exception instanceof FaceInferenceException ? $exception->reason : 'CANDIDATE_CHECK_FAILED');
				FaceErrorLogger::record('FACE_ENROLLMENT_CANDIDATE_ERROR', ['reason_code'=>$reason]);
				return $this->json(['state'=>'REJECTED','accepted'=>false,'reason_code'=>$reason,
					'error'=>1,'message'=>'Candidate check could not be completed']);
			}
			$diagnostic = $this->diagnosticFor($exception);
			FaceErrorLogger::record('FACE_ENROLLMENT_API_ERROR', [
				'action' => $action,
				'reason' => $diagnostic['reason'],
			]);
			return $this->json([
				'state' => 'REJECTED',
				'reason' => $diagnostic['reason'],
				'error' => 1,
				'message' => $diagnostic['message'],
			]);
		}
	}

	private function allowUncalibratedTesting(int $branchId): bool
	{
		$cfg = FaceAttendanceConfig::get(null, $branchId);
		return !empty($cfg['FACE_ENROLLMENT_UNCALIBRATED_TEST_MODE']);
	}

	private function invalidCaptureContext(string $message): string
	{
		return $this->json([
			'state' => 'REJECTED',
			'stage' => 'GUIDE_SELECTION',
			'reason' => 'INVALID_CAPTURE_CONTEXT',
			'reason_code' => 'INVALID_CAPTURE_CONTEXT',
			'error' => 1,
			'message' => $message,
		]);
	}

	private function diagnosticFor(Throwable $exception): array
	{
		$message = strtolower($exception->getMessage());
		if (strpos($message, 'table') !== false && (strpos($message, "doesn't exist") !== false || strpos($message, 'does not exist') !== false)) {
			return [
				'reason' => 'FACE_SCHEMA_NOT_READY',
				'message' => 'Face enrollment storage is not ready. Apply the face-attendance database migration.',
			];
		}
		if (strpos($message, 'inference') !== false || strpos($message, 'curl') !== false || strpos($message, 'connection') !== false) {
			return [
				'reason' => 'FACE_INFERENCE_NOT_READY',
				'message' => 'The private face inference service is not ready. Check its health status and runtime configuration.',
			];
		}
		return [
			'reason' => 'FACE_API_ERROR',
			'message' => 'Face enrollment request could not be completed.',
		];
	}

	private function json(array $response): string
	{
		$response = $this->withoutEmbeddings($response);
		$response = ['api_version' => 'v1', 'pad_status' => 'DEFERRED', 'commit_mode' => 'TEACHER_REVIEW', 'live_write' => false] + $response;
		return json_encode($response, JSON_UNESCAPED_SLASHES);
	}

	private function withoutEmbeddings(array $value): array
	{
		foreach ($value as $key => $item) {
			if (is_string($key) && preg_match('/(^embedding$|^embeddings$|_embeddings$)/i', $key)) {
				unset($value[$key]);
			} elseif (is_array($item)) {
				$value[$key] = $this->withoutEmbeddings($item);
			}
		}
		return $value;
	}

	public function streamReferencePortrait(array $request): void
	{
		$session = new session;
		// Identity and tenant come from existing authentication, never body overrides.
		$dbName = $session->check_session(isset($request['session_id']) ? ['session_id' => $request['session_id']] : []);
		$actorId = (int)get_uid();
		if (!$dbName || $actorId < 1) {
			http_response_code(401);
			exit;
		}

		$hasAccess = $session->access($actorId, $this->module('Member'), 1)
			|| $session->access($actorId, $this->module('Attendance'), 1)
			|| (!empty($_SESSION['admin']) && (int)$_SESSION['admin'] === 1);
		if (!$hasAccess) {
			http_response_code(403);
			exit;
		}

		$contactId = (int)($request['contact_id'] ?? 0);
		$memberId = (int)($request['member_id'] ?? 0);
		if ($contactId < 1 && $memberId < 1) {
			http_response_code(400);
			exit;
		}

		$key = FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY');
		$keyId = FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY_ID');
		if ($key === '' || $keyId === '') {
			http_response_code(500);
			exit;
		}

		try {
			$conn = $this->connect($dbName);
			if ($contactId < 1 && $memberId > 0) {
				$mRes = $conn->query("SELECT contact_id, bid FROM member WHERE id = '{$memberId}' LIMIT 1");
				$mRow = $mRes ? $mRes->fetch_assoc() : null;
				if (!$mRow) {
					$conn->close();
					http_response_code(404);
					exit;
				}
				$contactId = (int)$mRow['contact_id'];
				$memberBid = (int)$mRow['bid'];
			} else {
				$mRes = $conn->query("SELECT bid FROM member WHERE contact_id = '{$contactId}' LIMIT 1");
				$mRow = $mRes ? $mRes->fetch_assoc() : null;
				$memberBid = $mRow ? (int)$mRow['bid'] : 0;
			}

			$requesterBid = (int)get_bid();
			$isAdmin = (!empty($_SESSION['admin']) && (int)$_SESSION['admin'] === 1)
				|| (isset($_SESSION['position_id']) && (int)$_SESSION['position_id'] === 1);
			if (!$isAdmin && ($requesterBid < 1 || $memberBid < 1 || $requesterBid !== $memberBid)) {
				$conn->close();
				http_response_code(403);
				exit;
			}

			// Resolve the authorized existing portrait without sharing biometric caches.
			$refRes = $conn->query(
				"SELECT fir.id, fir.verified_at " .
				"FROM face_identity_reference fir " .
				"JOIN biometric_subject bs ON bs.id = fir.subject_id " .
				"WHERE bs.contact_id = '{$contactId}' AND fir.status = 'ACTIVE' " .
				"ORDER BY fir.id DESC LIMIT 1"
			);
			$refRow = $refRes ? $refRes->fetch_assoc() : null;

			if (!$refRow) {
				$conn->close();
				http_response_code(404);
				exit;
			}

			// Fetch the encrypted portrait only after existing member access checks.
			$refId = (int)$refRow['id'];
			$blobRes = $conn->query("SELECT encrypted_portrait FROM face_identity_reference WHERE id = '{$refId}' LIMIT 1");
			$blobRow = $blobRes ? $blobRes->fetch_assoc() : null;
			$conn->close();

			if (!$blobRow || empty($blobRow['encrypted_portrait'])) {
				http_response_code(404);
				exit;
			}

			$cipher = new FaceEmbeddingCipher($key, $keyId);
			$jpegBytes = $cipher->decryptBytes($blobRow['encrypted_portrait']);
			if (!is_string($jpegBytes) || $jpegBytes === '') {
				http_response_code(500);
				exit;
			}

			while (ob_get_level() > 0) {
				ob_end_clean();
			}

			http_response_code(200);
			header('Content-Type: image/jpeg');
			header('Content-Length: ' . strlen($jpegBytes));
			header('Cache-Control: no-store, private');
			header('X-Content-Type-Options: nosniff');
			header('Content-Disposition: inline; filename="face-' . $contactId . '.jpg"');
			echo $jpegBytes;
			exit;
		} catch (Throwable $e) {
			http_response_code(500);
			exit;
		}
	}
}

