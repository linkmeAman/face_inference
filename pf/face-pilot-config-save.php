<?php
header('Content-Type: application/json');

// ─── Auth ─────────────────────────────────────────────────────────────────────
require_once 'inc/check_session.php';
if (!isset($_SESSION['position_id']) || (int)$_SESSION['position_id'] !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
}

require_once dirname(__DIR__) . '/classes/services/FaceAttendanceConfig.php';

// ─── Parse incoming JSON ──────────────────────────────────────────────────────
$body = file_get_contents('php://input');
$incoming = json_decode($body, true);
if (!is_array($incoming)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON body']);
    exit;
}

// This page does not expose a live-write control. Retain the active setting
// when an administrator saves one of the other face feature flags.
if (!array_key_exists('FACE_ATTENDANCE_LIVE_WRITE', $incoming)
    && !array_key_exists('face_attendance_live_write', $incoming)) {
    $current = FaceAttendanceConfig::get();
    $incoming['FACE_ATTENDANCE_LIVE_WRITE'] = !empty($current['FACE_ATTENDANCE_LIVE_WRITE']);
}

// ─── Save directly to Database ────────────────────────────────────────────────
$userId = (int)($_SESSION['uid'] ?? 1);
$saved = FaceAttendanceConfig::set($incoming, $userId);

if (!$saved) {
    echo json_encode(['ok' => false, 'message' => 'Failed to save configuration to database.']);
    exit;
}

$latest = FaceAttendanceConfig::get();

echo json_encode([
    'ok' => true,
    'message' => 'Settings saved to the legacy database fallback at ' . date('H:i:s') . ' (Kolkata). .env values take precedence and no Nginx configuration was changed.',
    'config' => $latest
]);
