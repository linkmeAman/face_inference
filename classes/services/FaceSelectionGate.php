<?php

/** Tenant-wide, fail-closed selection switch. Never use a cached value here. */
class FaceSelectionGate
{
    public static function enabled(mysqli $db): bool
    {
        try {
            $result = $db->query('SELECT enabled FROM face_selection_control WHERE id = 1 LIMIT 1');
            $row = $result ? $result->fetch_assoc() : null;
            $serverFlag = class_exists('FaceAttendanceConfig')
                ? FaceAttendanceConfig::getEnv('FACE_TEACHER_SELECTION_ENABLED')
                : (getenv('FACE_TEACHER_SELECTION_ENABLED') ?: '');
            return $row !== null && (int)$row['enabled'] === 1
                && (string)$serverFlag === '1';
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** Disable first; then cancel scans. Sidecar eviction is best effort. */
    public static function disable(mysqli $db, FaceRecognitionProvider $provider): int
    {
        $db->begin_transaction();
        try {
            if (!$db->query('UPDATE face_selection_control SET enabled = 0 WHERE id = 1')) {
                throw new RuntimeException('Selection switch could not be disabled');
            }
            $result = $db->query("SELECT scan_token FROM attendance_scan WHERE status = 'ACTIVE'");
            if (!$result) throw new RuntimeException('Active scans could not be listed');
            $tokens = [];
            while ($row = $result->fetch_assoc()) $tokens[] = (string)$row['scan_token'];
            if (!$db->query("UPDATE attendance_scan SET status = 'CANCELLED', cancelled_at = CURRENT_TIMESTAMP WHERE status = 'ACTIVE'")) {
                throw new RuntimeException('Active scans could not be cancelled');
            }
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
        foreach ($tokens as $token) {
            try { $provider->deleteScan($token); } catch (Throwable $exception) { /* best effort */ }
        }
        return count($tokens);
    }
}
