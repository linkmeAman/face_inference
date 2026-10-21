<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$route = (string)file_get_contents($root . '/routes/v1/face-attendance.php');
$controller = (string)file_get_contents($root . '/controllers/faceAttendance.php');
$web = (string)file_get_contents($root . '/pf/js/controllers/batchAttendanceController.js');
$page = (string)file_get_contents($root . '/pf/attendance.php');
$contract = json_decode((string)file_get_contents($root . '/docs/api/face-attendance-v1.json'), true);
$failures = [];

function faceApi15Check(bool $condition, string $message): void
{
	global $failures;
	if (!$condition) $failures[] = $message;
}

faceApi15Check(strpos($route, "'reset-test-data' => 'resetFaceAttendanceTestData'") !== false, 'v1 route does not expose the reset operation');
faceApi15Check(strpos($controller, "'RESET FACE ATTENDANCE TEST DATA'") !== false, 'server reset confirmation is missing');
faceApi15Check(strpos($controller, 'ATTENDANCE_ACCESS') !== false, 'server does not enforce Attendance permission');
faceApi15Check(strpos($controller, 'UNAUTHORIZED_ADMIN_RESET') === false, 'reset must not require a separate position-1 role after Attendance permission passes');
faceApi15Check(strpos($controller, 'ACTIVE_FACE_SCAN') !== false && strpos($controller, "status = 'ACTIVE' FOR UPDATE") !== false, 'server does not refuse a reset during an active face scan');
faceApi15Check(strpos($controller, 'begin_transaction()') !== false && strpos($controller, 'rollback()') !== false && strpos($controller, 'commit()') !== false, 'reset is not transaction-protected');
faceApi15Check(strpos($controller, 'face_reset_attendance_ids') !== false, 'reset does not snapshot face-written attendance before deleting events');
faceApi15Check(strpos($controller, "DELETE FROM attendance_event WHERE source = 'FACE_MATCH' OR scan_id IS NOT NULL") !== false, 'reset is not limited to face attendance events');
faceApi15Check(strpos($controller, "'attendance' => 'DELETE FROM attendance WHERE id IN (SELECT id FROM face_reset_attendance_ids)'" ) !== false, 'reset can delete manual attendance rows');
faceApi15Check(strpos($controller, "'attendance' => 'DELETE FROM `attendance`'") === false, 'reset still deletes every attendance row');
foreach (['attendance_event', 'recognition_attempt', 'attendance_scan_roster', 'attendance_scan', 'attendance'] as $table) {
	faceApi15Check(strpos($controller, "'{$table}'") !== false, "reset does not clear {$table}");
}
faceApi15Check(strpos($controller, "'face_template'") === false && strpos($controller, "'face_model_template'") === false, 'reset must not delete enrolment or model templates');
faceApi15Check(strpos($controller, "'face_experiment_log'") === false && strpos($controller, "'face_experiment_purge_ledger'") === false, 'reset must not delete personal-data logger rows or the purge audit ledger');
faceApi15Check(strpos($web, 'function resetFaceAttendanceTestData()') !== false, 'attendance UI reset action is missing');
faceApi15Check(strpos($web, "'resetFaceAttendanceTestData'") !== false && strpos($web, "'v1/face-attendance.php'") !== false, 'attendance UI does not call the authenticated v1 reset endpoint');
faceApi15Check(strpos($web, 'window.prompt') !== false && strpos($web, 'requiredConfirmation') !== false && strpos($web, 'Swal.fire') !== false, 'attendance UI lacks typed and final reset confirmation');
faceApi15Check(strpos($page, 'Reset Face Attendance Test Data') !== false, 'reset button is missing from attendance');
faceApi15Check(strpos($web, '!$scope.faceAdminReviewer || $scope.faceResetBusy') === false, 'reset button is still hidden from authorised non-position-1 attendance users');
faceApi15Check(is_array($contract) && isset($contract['operations']['reset-test-data']), 'machine-readable reset contract is missing');
faceApi15Check(($contract['operations']['reset-test-data']['confirmation'] ?? '') === 'RESET FACE ATTENDANCE TEST DATA', 'machine-readable reset confirmation is wrong');
faceApi15Check(in_array('face_template', $contract['operations']['reset-test-data']['preserves'] ?? [], true), 'machine-readable contract does not preserve enrolment templates');
faceApi15Check(in_array('manual attendance', $contract['operations']['reset-test-data']['preserves'] ?? [], true), 'machine-readable contract does not preserve manual attendance');

if ($failures) {
	fwrite(STDERR, "FAIL FACE-API-15 test-data reset contract\n - " . implode("\n - ", $failures) . "\n");
	exit(1);
}
echo "PASS FACE-API-15 test-data reset contract\n";
