<?php
declare(strict_types=1);
require_once __DIR__ . '/dbconnection.class.php';
require_once __DIR__ . '/../controllers/telemetry.php';

function cleanupFaceSessions(mysqli $db): int {
  $result = $db->query("SELECT id,member_id FROM face_enrollment_capture_session WHERE status IN ('ACTIVE','PENDING_ADMIN_REVIEW') AND expires_at <= NOW() ORDER BY expires_at,id LIMIT 500");
  $expired = 0;
  while ($row = $result->fetch_assoc()) {
    $id = (int)$row['id'];
    $memberId = (int)$row['member_id'];
    $lockName = 'face_enrollment_' . substr(hash('sha256', (string)$memberId), 0, 48);
    $escaped = $db->real_escape_string($lockName);
    $lock = $db->query("SELECT GET_LOCK('{$escaped}',2) acquired")->fetch_assoc();
    if ((int)($lock['acquired'] ?? 0) !== 1) continue;
    try {
        $db->begin_transaction();
        $session = $db->query("SELECT id FROM face_enrollment_capture_session WHERE id='{$id}' AND status IN ('ACTIVE','PENDING_ADMIN_REVIEW') AND expires_at <= NOW() FOR UPDATE")->fetch_assoc();
        if ($session) {
            $db->query("UPDATE face_template SET status='REVOKED',encrypted_embedding='',revoked_at=NOW() WHERE capture_session_id='{$id}' AND status='PENDING_APPROVAL'");
            $db->query("UPDATE face_identity_reference SET status='CLEARED',encrypted_portrait='',encrypted_embedding='',cleared_at=NOW() WHERE capture_session_id='{$id}' AND status IN ('PENDING_ENROLLMENT','REJECTED_DUPLICATE')");
            $db->query("UPDATE face_enrollment_attempt SET decision='EXPIRED',two_burst_state='EXPIRED',completed_at=NOW() WHERE capture_session_id='{$id}' AND decision='PENDING_ADMIN_REVIEW'");
            $db->query("UPDATE face_enrollment_capture_session SET status='EXPIRED',reference_id=NULL,cleared_at=NOW() WHERE id='{$id}'");
            $expired++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        fwrite(STDERR, "Cleanup failed for session {$id}: {$e->getMessage()}\n");
        if (class_exists('telemetry')) {
            telemetry::log_server_error($e, 'ERROR', ['job' => 'cron.face_session_cleanup', 'session_id' => $id]);
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK('{$escaped}')");
    }
  }
  return $expired;
}

if (!defined('FACE_SESSION_CLEANUP_LIBRARY_ONLY')) {
    $database = 'ccc_database';
    foreach ($argv ?? [] as $arg) if (strpos($arg, '--database=') === 0) $database = substr($arg, 11);
    if (!in_array($database, ['ccc_database', 'ccc_database_test'], true)) {
        fwrite(STDERR, "Unsupported tenant database\n");
        exit(2);
    }
    $db = (new dbconnection())->connect($database);
    $expired = cleanupFaceSessions($db);
    $db->close();
    echo "Expired {$expired} capture sessions in {$database}\n";
}
