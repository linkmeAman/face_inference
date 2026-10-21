# Face Row-Selection Release Gate

`SELECT_PRESENT` only selects an eligible student row as Present in the attendance register. The teacher's manual "Punch Attendance" button is the sole authoritative attendance transaction boundary. `FACE_ATTENDANCE_LIVE_WRITE` remains `0`.

## Architecture & Controls

- **Dual-Key Gating via `FaceSelectionGate`**:
  Teacher-facing row selection requires two coordinated switches to be active:
  1. **Database switch**: Table `face_selection_control` row `id = 1` must have `enabled = 1`. If missing or 0, selection is denied.
  2. **Server configuration**: `FACE_TEACHER_SELECTION_ENABLED` must be `"1"` in `.env`, `nginx-face-params.conf`, and `/etc/nginx/snippets/face-attendance-params.conf`. Under PHP-FPM, `FaceAttendanceConfig::getEnv()` inspects `getenv()` and `$_SERVER` before checking `.env`.
- **Preflight Enforcement**:
  Before any camera hardware permission is requested, `batchAttendanceController.js` calls `/routes/v1/face-attendance.php?action=getFaceReadiness`. `controllers/faceAttendance.php` evaluates `FaceSelectionGate::enabled($conn) && $provider->checkReadiness()`. If either check fails, the preflight returns `SYSTEM_NOT_READY`, preventing the scan workflow from opening unready cameras.
- **Rollback / Immediate Disabling**:
  `php scripts/disable_face_selection.php --database=ccc_database` immediately sets `face_selection_control.enabled = 0`, cancels active scans, and evicts active inference sidecars without requiring an Nginx reload.
- **Branch Scope**:
  `FACE_ALLOWED_BRANCH_IDS` is configured for branches `1,2,4,5` (Kurla, Treon, Charni Road).

## Commit Boundary Invariant

- Face recognition never directly inserts attendance, absences, or membership deductions.
- A matched child transitions to `SELECT_PRESENT` (`PENDING_TEACHER_COMMIT`).
- Unscanned children remain Absent.
- Committing the attendance session requires the teacher to review the roster and click "Punch Attendance".
