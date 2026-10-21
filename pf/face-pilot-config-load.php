<?php
require_once 'inc/check_session.php';
if (!isset($_SESSION['position_id']) || (int)$_SESSION['position_id'] !== 1) {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/classes/services/FaceAttendanceConfig.php';

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'config' => FaceAttendanceConfig::get(),
    'source' => 'MySQL (`face_attendance_config` and `attendance_settings`)'
], JSON_PRETTY_PRINT);
