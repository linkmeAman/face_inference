<?php
require_once __DIR__."/AttendanceLedger.php";

/**
 * Shared member attendance transaction.
 *
 * This service owns the new attendance event and the existing attendance and
 * membership writes. Historical attendance rows remain untouched; new event
 * uniqueness is enforced by the attendance_event database key.
 */

if (!class_exists('AttendanceDomainException')) {
	class AttendanceDomainException extends RuntimeException
	{
		public $reason;

		public function __construct($reason, $message)
		{
			$this->reason = $reason;
			parent::__construct($message);
		}
	}
}

class AttendanceDomainService
{
	private $conn;
	private $testTablePrefix = '';

	public function __construct($conn, string $testTablePrefix = '')
	{
		if (!($conn instanceof mysqli)) {
			throw new InvalidArgumentException('AttendanceDomainService requires a mysqli connection');
		}
		if ($testTablePrefix !== '') {
			$name = $conn->query('SELECT DATABASE()')->fetch_row()[0];
			if ($testTablePrefix !== 'face_testing_' || !preg_match('/^ccc_database_face_pilot_[a-f0-9]{8}$/D', $name))
				throw new InvalidArgumentException('Test attendance requires the isolated pilot database');
		}
		$this->testTablePrefix = $testTablePrefix;
		$this->conn = $conn;
	}

	public function recordMemberAttendance(array $command): array
	{
		$command = $this->normalizeCommand($command);
		$lockName = $this->businessLockName($command);
		$transactionStarted = false;

		try {
			$this->acquireBusinessLock($lockName);
			$this->conn->begin_transaction();
			$transactionStarted = true;

			$this->enforceDatePolicy($command);
            $batch = $this->loadAuthorizedBatch($command);
			$this->enforceBatchNotCancelled($command);

			if ($command['person_type']==='M') { $m=$this->loadMember($command); if(!$m || (int)$m['park']!==0 || (int)$m['status']!==1 || (int)$m['bid']!==$command['branch_id']) throw new AttendanceDomainException('MEMBER_NOT_FOUND','Member is not active in this branch'); }
            $existingEvent = $this->findAttendanceEvent($command, true);
			if ($existingEvent) {
				$existing = $this->findAttendance($command, true);
                if (!$existing && !empty($existingEvent['attendance_id'])) {
                    $existing = AttendanceLedger::restore($this->conn,(int)$existingEvent['attendance_id'],$this->testTablePrefix);
                    $this->conn->commit();$transactionStarted=false;
                    return $this->result('RECORDED',$existing,$command,(int)$existingEvent['id']);
                }
				$this->conn->commit();
				$transactionStarted = false;
				return $this->result('ALREADY_MARKED', $existing ?: [], $command, (int)$existingEvent['id']);
			}

			$existing = $this->findAttendance($command, true);
			if ($command['event_type'] === 'PUNCH_OUT') {
				if (!$existing) {
					throw new AttendanceDomainException('NO_PUNCH_IN', 'Punch-out requires a recorded punch-in');
				}
				if ($existing['out_time'] !== '00:00:00') {
					$this->conn->commit();
					$transactionStarted = false;
					return $this->result('ALREADY_MARKED', $existing, $command);
				}

				$eventId = $this->insertAttendanceEvent($command, (int)$existing['id']);
				$time = $this->attendanceTime($command);
				$this->run("UPDATE attendance SET out_time = '{$time}', modified_by = '{$command['actor_employee_id']}' WHERE id = '{$existing['id']}'");
				$existing['out_time'] = $time;
				$existing['event_id'] = $eventId;
				$this->conn->commit();
				$transactionStarted = false;
				return $this->result('PUNCH_OUT_RECORDED', $existing, $command, $eventId);
			}

			if ($existing) {
				$this->conn->commit();
				$transactionStarted = false;
				return $this->result('ALREADY_MARKED', $existing, $command);
			}

			$membership = null;
			if ($command['person_type'] === 'M') {
				$member = $this->loadMember($command);
				if (!$member || (int)$member['park']!==0 || (int)$member['status']!==1) {
					throw new AttendanceDomainException('MEMBER_NOT_FOUND', 'Member is not present in this branch');
				}

                $membership = $this->loadMembership($command, $batch, true);
                if (!$membership) throw new AttendanceDomainException('INELIGIBLE','Member has no eligible membership for this batch and date');
                // The submitted inactive flag cannot turn an eligible punch into
                // a free observation. Expired observations require a separate action.
                if ($command['negative']===2) $command['negative']=0;
                $setting=$this->one("SELECT deduct_session_absentee FROM attendance_settings WHERE bid='{$command['branch_id']}'");
                $command['deduct_session_absentee']=(int)($setting['deduct_session_absentee']??0);
                $command['attendance_session']=(int)$membership['sessions']===0?0:($command['negative']===0?1:$command['deduct_session_absentee']);

			}

			$eventId = $this->insertAttendanceEvent($command);
			$attendanceId = $this->insertAttendance($command, $batch);
			$this->attachAttendanceEvent($eventId, $attendanceId);
			$deducted = $this->deductMembership($command, $membership, $attendanceId);
			$effect=['item_id'=>$membership?(int)$membership['item_id']:0,'deducted'=>$deducted,'absence_deducts'=>$command['deduct_session_absentee'],'applied'=>true];
            $meta=is_array($command['metadata'])?$command['metadata']:[];$meta['package_effect']=$effect;
            $json=$this->escape(json_encode($meta));
            $this->run("UPDATE attendance_event SET metadata='{$json}' WHERE id='{$eventId}'");
            $summary = $this->findAttendance($command, false);
			$this->conn->commit();
			$transactionStarted = false;

			$result = $this->result('RECORDED', $summary ?: ['id' => $attendanceId], $command, $eventId);
			$result['deducted_sessions'] = $deducted;
			return $result;
		} catch (AttendanceDomainException $exception) {
			if ($transactionStarted) {
				$this->conn->rollback();
			}
			return [
				'state' => 'REJECTED',
				'reason' => $exception->reason,
				'error' => 1,
				'message' => $exception->getMessage(),
				'current' => null,
			];
		} catch (Throwable $exception) {
			if ($transactionStarted) {
				$this->conn->rollback();
			}

			if ($this->isDuplicateException($exception)) {
				$current = $this->findAttendance($command, false);
				$event = $this->findAttendanceEvent($command, false);
				return $this->result('ALREADY_MARKED', $current ?: [], $command, $event ? (int)$event['id'] : 0);
			}
			throw $exception;
		} finally {
			$this->releaseBusinessLock($lockName);
		}
	}

	private function normalizeCommand(array $command): array
	{
		$required = [
			'branch_id', 'batch_id', 'attendance_date', 'session_id', 'member_id',
			'person_type', 'negative', 'source', 'event_type',
			'actor_employee_id', 'client_attempt_id',
		];
		foreach ($required as $field) {
			if (!array_key_exists($field, $command) || $command[$field] === '') {
				throw new InvalidArgumentException("Missing attendance command field: {$field}");
			}
		}

		$command['branch_id'] = (int)$command['branch_id'];
		$command['batch_id'] = (int)$command['batch_id'];
		$command['session_id'] = (int)$command['session_id'];
		$command['member_id'] = (int)$command['member_id'];
		$command['actor_employee_id'] = (int)$command['actor_employee_id'];
		$command['negative'] = (int)$command['negative'];
		$command['person_type'] = strtoupper((string)$command['person_type']);
		$command['source'] = strtoupper((string)$command['source']);
		$command['event_type'] = strtoupper((string)$command['event_type']);

		if ($command['branch_id'] < 1 || $command['batch_id'] < 1 || $command['member_id'] < 1 || $command['actor_employee_id'] < 1) {
			throw new InvalidArgumentException('Attendance command identifiers must be positive');
		}
		if ($command['session_id'] < 0 || !in_array($command['person_type'], ['M', 'E'], true)) {
			throw new InvalidArgumentException('Invalid attendance person or session');
		}
		if (!in_array($command['negative'], [0, 1, 2], true)) {
			throw new InvalidArgumentException('Invalid attendance negative value');
		}
		if (!in_array($command['source'], ['MANUAL', 'FACE_MATCH', 'TEACHER_CONFIRMED'], true) && !is_numeric($command['source'])) {
			throw new InvalidArgumentException('Invalid attendance source');
		}
		if (!in_array($command['event_type'], ['PUNCH_IN', 'PUNCH_OUT', 'ATTENDANCE_FINALIZED'], true)) {
			throw new InvalidArgumentException('Invalid attendance event type');
		}
		$date = DateTime::createFromFormat('!Y-m-d', (string)$command['attendance_date']);
		if (!$date || $date->format('Y-m-d') !== $command['attendance_date']) {
			throw new InvalidArgumentException('Invalid attendance date');
		}
		if (empty($command['client_attempt_id'])) {
			throw new InvalidArgumentException('Client attempt id is required');
		}

		if ($command['person_type'] === 'E') {
			$memberId = (int)$command['member_id'];
			$isEmp = (int)$this->scalar("SELECT COUNT(id) FROM employee WHERE contact_id = '{$memberId}' AND status = '1' AND park = '0'");
			if ($isEmp === 0) {
				$command['person_type'] = 'M';
			}
		}

		$command['check_month'] = !empty($command['check_month']);
		$command['item_id'] = isset($command['item_id']) ? (int)$command['item_id'] : 0;
		$command['attendance_session'] = isset($command['attendance_session']) ? (int)$command['attendance_session'] : ($command['negative'] === 0 ? 1 : 0);
		$command['deduct_session_absentee'] = isset($command['deduct_session_absentee']) ? (int)$command['deduct_session_absentee'] : 0;
		$command['code'] = isset($command['code']) ? (string)$command['code'] : '';
		$command['comment'] = isset($command['comment']) ? (string)$command['comment'] : '';
		$command['row_color'] = isset($command['row_color']) ? (int)$command['row_color'] : 0;
		$command['attendance_time'] = isset($command['attendance_time']) ? (string)$command['attendance_time'] : date('H:i:s');
		$command['scan_id'] = isset($command['scan_id']) ? (int)$command['scan_id'] : 0;
		$command['occurred_at_device'] = isset($command['occurred_at_device']) ? (string)$command['occurred_at_device'] : '';
		$command['metadata'] = isset($command['metadata']) ? $command['metadata'] : null;

		return $command;
	}

	private function loadAuthorizedBatch(array $command): array
	{
		$batchId = $command['batch_id'];
		$batch = $this->one("SELECT id, bid, venue_id, batch, completed_sessions, parent_id,start_date,end_date FROM batch WHERE id = '{$batchId}' AND park = '0' LIMIT 1 FOR UPDATE");
		if (!$batch || (int)$batch['bid'] !== $command['branch_id']) {
			throw new AttendanceDomainException('BRANCH_NOT_AUTHORIZED', 'Batch is not authorized for this branch');
		}
        if($command['attendance_date']<$batch['start_date'] || ($batch['end_date'] && $batch['end_date']!=='0000-00-00' && $command['attendance_date']>$batch['end_date']))throw new AttendanceDomainException('BATCH_DATE','Batch is not running on this date');
        $weekday=(int)date('w',strtotime($command['attendance_date']));
        if(!$this->scalar("SELECT COUNT(*) FROM batch_time WHERE batch_id='{$batchId}' AND day_code=$weekday AND park=0"))throw new AttendanceDomainException('BATCH_DAY','Batch is not scheduled on this weekday');
        return $batch;
    }

	private function enforceDatePolicy(array $command): void
	{
		if (date('Y-m', strtotime($command['attendance_date'])) !== date('Y-m')) {
			$allowed = false;
			$actor = (int)($command['actor_employee_id'] ?? 0);
			if ($actor > 0 && class_exists('session')) {
				$session = new session();
				if (method_exists($session, 'can_edit_previous_month')) {
					$allowed = $session->can_edit_previous_month($actor);
				}
			}
			if (!$allowed) {
				throw new AttendanceDomainException('DATE_POLICY', 'Previous-month attendance is not allowed');
			}
		}
	}

	private function enforceBatchNotCancelled(array $command): void
	{
		$batchId = (int)$command['batch_id'];
		$date = $this->escape($command['attendance_date']);
		$cancelled = $this->one("SELECT id, status FROM attendance WHERE batch_id = '{$batchId}' AND date = '{$date}' AND contact_id = '0' AND status IN ('2', '3') AND type = 'B' AND park = '0' LIMIT 1");
		if ($cancelled) {
			$msg = (int)$cancelled['status'] === 3 ? 'Batch session is rescheduled on this date' : 'Batch session is cancelled on this date';
			throw new AttendanceDomainException('BATCH_CANCELLED', $msg);
		}
	}

	private function loadMember(array $command)
	{
		$memberId = $command['member_id'];
		return $this->one("SELECT contact_id, status, park, bid FROM member WHERE contact_id = '{$memberId}' LIMIT 1 FOR UPDATE");
	}

	private function loadMembership(array $command, array $batch, bool $lockItem)
	{
		$memberId = $command['member_id'];
		$date = $this->escape($command['attendance_date']);
		$batchIds = [(int)$batch['id']];
		if (!empty($batch['parent_id'])) {
			$batchIds[] = (int)$batch['parent_id'];
		}
		$batchFilter = implode(',', array_unique($batchIds));
		$itemFilter = $command['item_id'] > 0 ? " AND item_id = '{$command['item_id']}'" : '';
		$membership = $this->one("SELECT item_id, start_date, end_date, sessions, sessions_used, batch_id FROM invoice_invoiceitem_view WHERE contact_id = '{$memberId}' AND batch_id IN ({$batchFilter}) AND start_date <= '{$date}' AND end_date >= '{$date}' AND bid = '{$command['branch_id']}' AND park = '0' AND item_park = '0' AND quotation = '0' AND (sessions = 0 OR sessions > sessions_used){$itemFilter} ORDER BY start_date ASC LIMIT 1");
		if (!$membership) {
			return null;
		}

		$itemId = (int)$membership['item_id'];
		$lock = $lockItem ? ' FOR UPDATE' : '';
		$item = $this->one("SELECT id, sessions, sessions_used FROM invoice_item WHERE id = '{$itemId}' LIMIT 1{$lock}");
		if (!$item) {
			throw new AttendanceDomainException('MEMBERSHIP_NOT_FOUND', 'Membership item is not available');
		}
		$membership['item_id'] = $itemId;
		$membership['sessions'] = (int)$item['sessions'];
		$membership['sessions_used'] = (int)$item['sessions_used'];
		return $membership;
	}

	private function findAttendance(array $command, bool $forUpdate)
	{
		$memberId = $command['member_id'];
		$batchId = $command['batch_id'];
		$date = $this->escape($command['attendance_date']);
		$type = $this->escape($command['person_type']);
		$sessionId = $command['session_id'];
		$lock = $forUpdate ? ' FOR UPDATE' : '';
		return $this->one("SELECT id, date, in_time, out_time, contact_id, batch_id, session_id, negative, type, status FROM attendance WHERE contact_id = '{$memberId}' AND batch_id = '{$batchId}' AND date = '{$date}' AND session_id = '{$sessionId}' AND type = '{$type}' AND park = '0' ORDER BY id DESC LIMIT 1{$lock}");
	}

	private function findAttendanceEvent(array $command, bool $forUpdate)
	{
		$date = $this->escape($command['attendance_date']);
		$type = $this->escape($command['person_type']);
		$eventType = $this->escape($command['event_type']);
		$lock = $forUpdate ? ' FOR UPDATE' : '';
		return $this->one("SELECT id, attendance_id, event_type FROM attendance_event WHERE batch_id = '{$command['batch_id']}' AND session_id = '{$command['session_id']}' AND attendance_date = '{$date}' AND person_type = '{$type}' AND person_id = '{$command['member_id']}' AND event_type = '{$eventType}' LIMIT 1{$lock}");
	}

	private function insertAttendanceEvent(array $command, int $attendanceId = 0): int
	{
		$date = $this->escape($command['attendance_date']);
		$personType = $this->escape($command['person_type']);
		$eventType = $this->escape($command['event_type']);
		$source = $this->escape($command['source']);
		$clientAttemptId = $this->escape($command['client_attempt_id']);
		$deviceTime = $this->eventDeviceTime($command['occurred_at_device']);
		$metadata = $this->eventMetadata($command['metadata']);
		$attendanceValue = $attendanceId > 0 ? (string)$attendanceId : 'NULL';
		$scanValue = $command['scan_id'] > 0 ? (string)$command['scan_id'] : 'NULL';

		$sql = "INSERT INTO attendance_event (branch_id, batch_id, session_id, attendance_date, person_type, person_id, event_type, source, actor_employee_id, attendance_id, scan_id, client_attempt_id, occurred_at_server, occurred_at_device, metadata) VALUES ('{$command['branch_id']}', '{$command['batch_id']}', '{$command['session_id']}', '{$date}', '{$personType}', '{$command['member_id']}', '{$eventType}', '{$source}', '{$command['actor_employee_id']}', {$attendanceValue}, {$scanValue}, '{$clientAttemptId}', CURRENT_TIMESTAMP, {$deviceTime}, {$metadata})";
		$this->run($sql);
		return (int)$this->conn->insert_id;
	}

	private function attachAttendanceEvent(int $eventId, int $attendanceId): void
	{
		$this->run("UPDATE attendance_event SET attendance_id = '{$attendanceId}' WHERE id = '{$eventId}'");
	}

	private function eventDeviceTime(string $value): string
	{
		if ($value === '') {
			return 'NULL';
		}
		$date = DateTime::createFromFormat('!Y-m-d H:i:s', $value);
		if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
			throw new AttendanceDomainException('INVALID_DEVICE_TIME', 'Invalid device event time');
		}
		return "'" . $this->escape($value) . "'";
	}

	private function eventMetadata($value): string
	{
		if ($value === null || $value === '') {
			return 'NULL';
		}
		$json = is_string($value) ? $value : json_encode($value);
		if (!is_string($json) || (json_last_error() !== JSON_ERROR_NONE && $json !== 'null')) {
			throw new AttendanceDomainException('INVALID_EVENT_METADATA', 'Invalid event metadata');
		}
		return "'" . $this->escape($json) . "'";
	}

	private function insertAttendance(array $command, array $batch): int
	{
		$date = $this->escape($command['attendance_date']);
		$time = $this->attendanceTime($command);
		$code = $this->escape($command['code']);
		$type = $this->escape($command['person_type']);
		$sourceCode = $this->sourceCode($command['source']);
		$sourceLabel = $this->escape($command['source']);
		$comment = $this->escape($command['comment']);
		$batchName = $this->escape($batch['batch']);
		$attendanceCount = (int)$this->scalar("SELECT COUNT(id) + 1 FROM attendance WHERE contact_id = '{$command['member_id']}' AND type = '{$type}' AND park = '0'");
		$classNo = ((int)$batch['completed_sessions']) + 1;
		$session = (int)$command['attendance_session'];
		$negative = (int)$command['negative'];
		$memberId = (int)$command['member_id'];
		$batchId = (int)$command['batch_id'];
		$sessionId = (int)$command['session_id'];
		$actor = (int)$command['actor_employee_id'];
		$branch = (int)$command['branch_id'];
		$venue = (int)$batch['venue_id'];
		$rowColor = (int)$command['row_color'];

		$sql = "INSERT INTO attendance (date, in_time, contact_id, batch_id, venue_id, code, session_id, negative, session, user_id, bid, source, final_source, type, status, attendance_count, class_no, batch, comment, row_color, created_by, modified_by) VALUES ('{$date}', '{$time}', '{$memberId}', '{$batchId}', '{$venue}', '{$code}', '{$sessionId}', '{$negative}', '{$session}', '{$actor}', '{$branch}', '{$sourceCode}', '{$sourceLabel}', '{$type}', '1', '{$attendanceCount}', '{$classNo}', '{$batchName}', '{$comment}', '{$rowColor}', '{$actor}', '{$actor}')";
		$this->run($sql);
		return (int)$this->conn->insert_id;
	}

	private function deductMembership(array $command, $membership, int $attendanceId): int
	{
		if ($command['person_type'] !== 'M' || !$membership) {
			return 0;
		}
		if ((int)$membership['sessions'] === 0) {
			return 0;
		}

		$deduct = 0;
		if ((int)$command['negative'] === 0) {
			$deduct = 1;
		} elseif ((int)$command['negative'] === 1) {
			$deduct = $command['deduct_session_absentee'] > 0 ? 1 : 0;
		}
		if ($deduct === 0) {
			return 0;
		}
		if ((int)$membership['sessions_used'] + $deduct > (int)$membership['sessions']) {
			throw new AttendanceDomainException('MEMBERSHIP_EXHAUSTED', 'Membership has no remaining sessions');
		}

		$itemId = (int)$membership['item_id'];
		$actor = (int)$command['actor_employee_id'];
		$this->run("UPDATE invoice_item SET sessions_used = sessions_used + '{$deduct}', modified_by = '{$actor}' WHERE id = '{$itemId}' AND sessions_used + '{$deduct}' <= sessions");
		if ($this->conn->affected_rows !== 1) {
			throw new AttendanceDomainException('MEMBERSHIP_EXHAUSTED', 'Membership has no remaining sessions');
		}
		$this->run("UPDATE attendance SET sessions_completed = (SELECT sessions_used FROM invoice_item WHERE id = '{$itemId}') WHERE id = '{$attendanceId}'");
		return $deduct;
	}

	private function sourceCode($source): int
	{
		if (is_numeric($source)) {
			return (int)$source;
		}
		return [
			'MANUAL' => 4,
			'FACE_MATCH' => 5,
			'TEACHER_CONFIRMED' => 4,
		][$source];
	}

	private function attendanceTime(array $command): string
	{
		$time = DateTime::createFromFormat('!H:i:s', $command['attendance_time']);
		return $time && $time->format('H:i:s') === $command['attendance_time'] ? $command['attendance_time'] : date('H:i:s');
	}

	private function businessLockName(array $command): string
	{
		$key = $this->conn->query('SELECT DATABASE()')->fetch_row()[0].':'.implode(':', [
			$command['branch_id'], $command['batch_id'], $command['attendance_date'],
			$command['session_id'], $command['person_type'], $command['member_id'],
		]);
		return 'attendance_domain_' . substr(hash('sha256', $key), 0, 46);
	}

	private function acquireBusinessLock(string $lockName): void
	{
		$lock = $this->escape($lockName);
		$row = $this->one("SELECT GET_LOCK('{$lock}', 10) AS acquired");
		if (!$row || (int)$row['acquired'] !== 1) {
			throw new RuntimeException('Could not acquire attendance business lock');
		}
	}

	private function releaseBusinessLock(string $lockName): void
	{
		$lock = $this->escape($lockName);
		try {
			$this->run("SELECT RELEASE_LOCK('{$lock}')");
		} catch (Throwable $ignored) {
			// The attendance result is already determined; release is best effort.
		}
	}

	private function result(string $state, array $attendance, array $command, int $eventId = 0): array
	{
		$result = [
			'state' => $state,
			'error' => 0,
			'attendance_id' => isset($attendance['id']) ? (int)$attendance['id'] : 0,
			'negative' => $command['negative'],
			'session_id' => $command['session_id'],
			'member_id' => $command['member_id'],
			'event_type' => $command['event_type'],
			'current' => $attendance,
		];
		if ($eventId > 0) {
			$result['event_id'] = $eventId;
		}
		return $result;
	}

	private function isDuplicateException(Throwable $exception): bool
	{
		return $exception instanceof mysqli_sql_exception && (int)$exception->getCode() === 1062;
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
        if ($this->testTablePrefix !== '') {
            // Rewrite table tokens only; quoted member/batch/metadata values
            // remain byte-for-byte unchanged.
            $pattern = <<<'SQL_TABLE'
~'(?:\\.|''|[^'\\])*'(*SKIP)(*F)|\b(FROM|INTO|UPDATE|JOIN) (attendance_event|attendance)\b~i
SQL_TABLE;
            $sql = preg_replace($pattern, '$1 '.$this->testTablePrefix.'$2', $sql);
        }
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
