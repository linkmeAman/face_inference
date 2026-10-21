<?php
$controller = file_get_contents(__DIR__ . '/../../controllers/member.php');
$browser = file_get_contents(__DIR__ . '/../../pf/js/controllers/batchAttendanceController.js');
$checks = [
    'browser sends explicit date' => strpos($browser, 'date: attendanceDate') !== false,
    'browser sends attendance session' => strpos($browser, 'attendance_session_id: $scope.batch.session_id || 0') !== false,
    'browser does not issue per-row attendance requests' => strpos($browser, '"table": "attendance_cont_view"') === false,
    'server bulk-loads candidate rows' => strpos($controller, '$candidateRows = array();') !== false,
    'server bulk-loads available contacts' => strpos($controller, '$availableContacts = array();') !== false,
    'face mappings are roster scoped' => strpos($controller, '`m`.`contact_id` IN ({$contactIdSql})') !== false,
    'attendance is bulk loaded by session' => strpos($controller, '$attendanceByContact = array();') !== false && strpos($controller, '`session_id` = \'{$attendanceSessionId}\'') !== false,
    'response includes authoritative state' => strpos($controller, '$row[\'attendance_id\']') !== false && strpos($controller, '$row[\'negative\']') !== false,
];
$failed = 0;
foreach ($checks as $label => $passed) { echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$passed) $failed++; }
exit($failed ? 1 : 0);