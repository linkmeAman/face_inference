<?php

require_once dirname(__DIR__) . '/classes/dbconnection.class.php';
require_once dirname(__DIR__) . '/classes/session.class.php';
require_once dirname(__DIR__) . '/classes/services/FaceAttendanceConfig.php';
require_once dirname(__DIR__) . '/classes/services/FaceAttendanceApiService.php';
require_once dirname(__DIR__) . '/classes/services/FaceErrorLogger.php';
require_once dirname(__DIR__) . '/classes/clients/FaceInferenceClient.php';
require_once dirname(__DIR__) . '/classes/providers/FaceRecognitionProviderFactory.php';
require_once dirname(__DIR__) . '/inc/defaults.php';

class faceAttendance extends dbconnection
{
	public function handle(string $action, array $request): string
	{
		$session = new session;
		$dbName = $session->check_session($request);
		if (!$dbName) {
			return $this->json(['state' => 'REJECTED', 'reason' => 'SESSION_REQUIRED', 'error' => 1, 'message' => 'A valid application session is required']);
		}

		$actorId = (int)(get_uid() ?: 0);
		if ($actorId < 1 || !$session->access($actorId, $this->module('Attendance'), 1)) {
			return $this->json(['state' => 'REJECTED', 'reason' => 'ATTENDANCE_ACCESS', 'error' => 1, 'message' => 'Attendance permission is required']);
		}
		if ($action === 'resetFaceAttendanceTestData') {
			return $this->resetFaceAttendanceTestData($dbName, $actorId, $request);
		}
		$request['actor_employee_id'] = $actorId;
		$request['browser_session_binding'] = hash('sha256', session_id());
		$branchId = isset($request['branch_id']) ? (int)$request['branch_id'] : 0;
		if ($action === 'startFaceScan' && $branchId < 1) {
			return $this->json(['state' => 'REJECTED', 'reason' => 'BRANCH_REQUIRED', 'error' => 1, 'message' => 'Selected branch is required']);
		}
		if ($branchId > 0 && isset($_SERVER['HTTP_BID']) && $_SERVER['HTTP_BID'] !== '' && (int)$_SERVER['HTTP_BID'] !== $branchId) {
			return $this->json(['state' => 'REJECTED', 'reason' => 'BRANCH_ACCESS', 'error' => 1, 'message' => 'Selected branch is not authorized']);
		}

		$key = FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY');
		$keyId = FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY_ID');
		if ($key === '' || $keyId === '') {
			return $this->json(['state' => 'REJECTED', 'reason' => 'FACE_CONFIGURATION', 'error' => 1, 'message' => 'Face attendance encryption is not configured']);
		}
		try {
			$conn = $this->connect($dbName);
			$provider = FaceRecognitionProviderFactory::create();
			$config = FaceAttendanceFeatureConfig::fromDatabase($conn, $branchId);
			if (!$config->enabled) {
				$conn->close();
				return $this->json(['state' => 'REJECTED', 'reason' => 'FEATURE_DISABLED', 'error' => 1, 'message' => 'Face attendance is disabled']);
			}
				$service = new FaceAttendanceApiService($conn, $provider, new FaceEmbeddingCipher($key, $keyId), $config);
				switch ($action) {
					case 'getFaceReadiness':
						$status = FaceSelectionGate::enabled($conn) ? $service->readinessStatus() : ['ready' => false, 'retryable' => false, 'reason' => 'SYSTEM_NOT_READY'];
						$result = $status['ready'] ? ['state' => 'READY'] : ['state' => 'REJECTED', 'outcome' => 'SYSTEM_NOT_READY',
							'reason' => $status['reason'], 'message' => 'Face scanning is temporarily unavailable. Please wait or use the attendance list.',
							'retryable' => $status['retryable'], 'retry_after_ms' => $status['retryable'] ? 1000 : 0,
							'recovery_action' => $status['retryable'] ? 'CHECK_STATUS' : 'STOP'];
						break;
					case 'previewFace':
						if (empty($request['frame']) || !is_string($request['frame'])) {
							$result = ['state' => 'REJECTED', 'reason' => 'INVALID_FRAME', 'error' => 1, 'message' => 'Face preview frame is required'];
							break;
						}
						$captureContext = $this->decodeCaptureContext($request['capture_context'] ?? null);
						if (array_key_exists('capture_context', $request) && $captureContext === null) {
							$result = ['state' => 'REJECTED', 'reason' => 'INVALID_CAPTURE_CONTEXT', 'error' => 1, 'message' => 'Capture geometry is invalid'];
							break;
						}
						$result = array_merge(['state' => 'PREVIEW'], $provider->previewAttendance($request['frame'], $captureContext));
						break;
					case 'startFaceScan':
					$result = $service->startFaceScan($request);
					break;
				case 'getCaptureNonce':
					$result = $service->issueCaptureNonce($request['scan_id'] ?? 0, (string)($request['scan_token'] ?? ''), $actorId, $request['browser_session_binding']);
					break;
				case 'renewFaceScan':
					$result = $service->renewFaceScan($request['scan_id'] ?? 0, (string)($request['scan_token'] ?? ''), $actorId, $request['browser_session_binding']);
					break;
				case 'recognizeFaceAttempt':
					$captureContexts = $this->decodeCaptureContexts($request['capture_contexts'] ?? []);
					if (array_key_exists('capture_contexts', $request) && $captureContexts === null) {
						$result = ['state' => 'REJECTED', 'stage' => 'GUIDE_SELECTION', 'reason' => 'INVALID_CAPTURE_CONTEXT', 'reason_code' => 'INVALID_CAPTURE_CONTEXT', 'error' => 1, 'message' => 'Capture geometry is invalid'];
						break;
					}
					$frames = $this->decodeFrames(isset($request['frames']) && is_array($request['frames']) ? $request['frames'] : []);
					if (array_key_exists('capture_contexts', $request) && count($captureContexts ?? []) !== count($frames)) {
						$result = ['state' => 'REJECTED', 'stage' => 'GUIDE_SELECTION', 'reason' => 'INVALID_CAPTURE_CONTEXT', 'reason_code' => 'INVALID_CAPTURE_CONTEXT', 'error' => 1, 'message' => 'Capture geometry must match every submitted frame'];
						break;
					}
					$clientZVariance = array_key_exists('client_z_variance', $request) && is_numeric($request['client_z_variance'])
						? (float)$request['client_z_variance'] : null;
					$result = $service->recognizeFaceAttempt($request['scan_id'] ?? 0, (string)($request['scan_token'] ?? ''), $actorId, (string)($request['client_attempt_id'] ?? ''), $frames, (string)($request['challenge_token'] ?? ''), $captureContexts ?? [], (string)($request['capture_nonce'] ?? ''), $request['browser_session_binding'], $clientZVariance);
					break;
				case 'getFaceScanStatus':
					$result = $service->getFaceScanStatus($request['scan_id'] ?? 0, (string)($request['scan_token'] ?? ''), $actorId, (string)($request['client_attempt_id'] ?? ''), $request['browser_session_binding']);
					break;
				case 'completeFaceScan':
					$result = $service->completeFaceScan($request['scan_id'] ?? 0, (string)($request['scan_token'] ?? ''), $actorId, !empty($request['confirm_absent']));
					break;
				case 'cancelFaceScan':
					$result = $service->cancelFaceScan($request['scan_id'] ?? 0, (string)($request['scan_token'] ?? ''), $actorId);
					break;
				default:
					$result = ['state' => 'REJECTED', 'reason' => 'BAD_ACTION', 'error' => 1, 'message' => 'Unknown face attendance action'];
			}
			$conn->close();
			return $this->json($result);
		} catch (Throwable $exception) {
			FaceErrorLogger::record('FACE_API_ERROR', ['action' => $action, 'reason' => 'FACE_API_ERROR']);
			return $this->json(['state' => 'REJECTED', 'reason' => 'FACE_API_ERROR', 'error' => 1, 'message' => 'Face attendance request could not be completed']);
		}
	}

	/**
	 * Test-only operational reset. It clears only records created by face
	 * workflows, never manual attendance, enrolment templates, or the test roster.
	 * The purge ledger is retained because it is an audit record, not test data.
	 */
	private function resetFaceAttendanceTestData(string $dbName, int $actorId, array $request): string
	{
		$confirmation = trim((string)($request['confirmation'] ?? ''));
		if (!hash_equals('RESET FACE ATTENDANCE TEST DATA', $confirmation)) {
			return $this->json(['state' => 'REJECTED', 'reason' => 'RESET_CONFIRMATION_REQUIRED', 'error' => 1, 'message' => 'Type the required reset confirmation exactly', 'data' => []]);
		}

		$conn = $this->connect($dbName);
		$lockHeld = false;
		$inTransaction = false;
		try {
			$lock = $conn->query("SELECT GET_LOCK('face_attendance_test_data_reset', 5) AS acquired");
			$lockRow = $lock ? $lock->fetch_assoc() : null;
			if (!$lockRow || (int)$lockRow['acquired'] !== 1) {
				return $this->json(['state' => 'REJECTED', 'reason' => 'RESET_BUSY', 'error' => 1, 'message' => 'Another reset is in progress; try again shortly', 'data' => []]);
			}
			$lockHeld = true;
			$conn->begin_transaction();
			$inTransaction = true;
			$active = $conn->query("SELECT COUNT(*) AS total FROM attendance_scan WHERE status = 'ACTIVE' FOR UPDATE");
			$activeRow = $active ? $active->fetch_assoc() : null;
			if ((int)($activeRow['total'] ?? 0) > 0) {
				$conn->rollback();
				$inTransaction = false;
				return $this->json(['state' => 'REJECTED', 'reason' => 'ACTIVE_FACE_SCAN', 'error' => 1, 'message' => 'Finish or cancel the active face scan before resetting test data', 'data' => []]);
			}

			if (!$conn->query("CREATE TEMPORARY TABLE face_reset_attendance_ids AS SELECT DISTINCT attendance_id AS id FROM attendance_event WHERE attendance_id IS NOT NULL AND (source = 'FACE_MATCH' OR scan_id IS NOT NULL)")) {
				throw new RuntimeException('Attendance test reset could not collect face attendance rows');
			}
			$deletes = [
				'attendance_event' => "DELETE FROM attendance_event WHERE source = 'FACE_MATCH' OR scan_id IS NOT NULL",
				'recognition_attempt' => 'DELETE FROM recognition_attempt',
				'attendance_scan_roster' => 'DELETE FROM attendance_scan_roster',
				'attendance_scan' => 'DELETE FROM attendance_scan',
				'attendance' => 'DELETE FROM attendance WHERE id IN (SELECT id FROM face_reset_attendance_ids)',
			];
			$deleted = [];
			foreach ($deletes as $table => $sql) {
				if (!$conn->query($sql)) throw new RuntimeException('Attendance test reset could not clear a table');
				$deleted[$table] = $conn->affected_rows;
			}
			$conn->query('DROP TEMPORARY TABLE IF EXISTS face_reset_attendance_ids');
			$conn->commit();
			$inTransaction = false;
			FaceErrorLogger::record('FACE_ATTENDANCE_TEST_DATA_RESET', ['actor_employee_id' => $actorId, 'deleted' => $deleted]);
			return $this->json(['state' => 'RESET', 'error' => 0, 'error_msg' => '', 'message' => 'Face attendance test data was reset', 'data' => ['deleted' => $deleted]]);
		} catch (Throwable $exception) {
			if ($inTransaction) {
				$conn->rollback();
			}
			FaceErrorLogger::record('FACE_ATTENDANCE_TEST_DATA_RESET_FAILED', ['actor_employee_id' => $actorId]);
			return $this->json(['state' => 'REJECTED', 'reason' => 'RESET_FAILED', 'error' => 1, 'message' => 'Face attendance test data could not be reset', 'data' => []]);
		} finally {
			if ($lockHeld) {
				$conn->query("SELECT RELEASE_LOCK('face_attendance_test_data_reset')");
			}
			$conn->close();
		}
	}

	private function json(array $response): string
	{
		if (($response['state'] ?? '') === 'REJECTED' && !isset($response['outcome'])) {
			$response['outcome'] = 'SYSTEM_NOT_READY';
		}
		$response = $response + ['retryable' => false, 'retry_after_ms' => 0, 'recovery_action' => 'STOP'];
		$response = ['api_version' => 'v1', 'pad_status' => 'DEFERRED', 'commit_mode' => 'TEACHER_REVIEW', 'live_write' => false] + $response;
		return json_encode($response, JSON_UNESCAPED_SLASHES);
	}

	private function decodeFrames(array $frames): array
	{
		$decoded = [];
		foreach ($frames as $frame) {
			if (!is_string($frame) || $frame === '') {
				$decoded[] = $frame;
				continue;
			}
			if (preg_match('/^data:[^;]+;base64,(.*)$/s', $frame, $matches)) {
				$value = base64_decode($matches[1], true);
				$decoded[] = $value === false ? '' : $value;
			} else {
				$decoded[] = $frame;
			}
		}
		return $decoded;
	}

	private function decodeCaptureContext($value): ?array
	{
		if (is_string($value)) {
			$value = json_decode($value, true);
		}
		return is_array($value) ? $value : null;
	}

	private function decodeCaptureContexts($value): ?array
	{
		if (is_string($value)) {
			$value = json_decode($value, true);
		}
		if (!is_array($value)) return null;
		$result = [];
		foreach ($value as $context) {
			if (!is_array($context)) return null;
			$result[] = $context;
		}
		return $result;
	}
}
