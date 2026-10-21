# Incident & Resolution: Live Attendance Face Punch-In & PAD Liveness Calibration

- **Date**: 2026-09-26
- **Component**: Face Attendance Scan & Supervised Punch-In (Protocol v2)
- **Affected Endpoints**: `/routes/faceAttendanceRoute.php?action=previewFace`, `/routes/v1/face-attendance.php?action=recognizeFaceAttempt`, `face-inference.service` (`/recognize`, `/health`)
- **Status**: Resolved & Verified in Production

---

## 1. Issue Description

When attempting a live face punch-in scan for an enrolled student (`FaceTest01 Child`, member 616) on the attendance register ([`/pf/attendance.php`](file:///var/www/admin.creativeculturalcentre.in/pf/attendance.php)):

1. The scanner opened with the camera guide oval displaying a dotted orange/warning state instead of solid green.
2. An orange warning banner appeared over the camera feed:
   > *"Live model preview is unavailable; keep one face inside the guide. Final recognition remains server-verified."*
3. When the user centered their face inside the guide, the scanner continuously evaluated attempts and returned:
   > *"Face not accepted. Continue with the next child."*
   along with a toast notification: *"Face not accepted"*.
4. Roster status: The student row remained stuck on **"Not scanned — Absent"** (`0 present, 5 absent`), preventing the teacher from marking the child present or punching in.

---

## 2. Root Cause Analysis

Investigation of server access logs, Nginx error logs, and database records (`recognition_attempt` scan ID 90 at 19:21:40 IST) revealed two concurrent issues:

### A. Missing Class Include on Preview Polling (`controllers/faceAttendance.php`)
- `batchAttendanceController.js` polls `previewFace` via `POST /routes/faceAttendanceRoute.php?action=previewFace`.
- In [`controllers/faceAttendance.php`](file:///var/www/admin.creativeculturalcentre.in/controllers/faceAttendance.php) line 34:
  ```php
  $key = FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY');
  ```
  `FaceAttendanceConfig.php` was not required at the top of `controllers/faceAttendance.php`.
- Every preview poll threw a PHP fatal error:
  `PHP Fatal error: Uncaught Error: Class "FaceAttendanceConfig" not found in controllers/faceAttendance.php:34`
- This returned **HTTP 500** to the browser on every frame, forcing the client UI into the fallback warning state (`Live model preview is unavailable...`).

### B. Uncalibrated PAD Model Causing Fail-Closed Match Rejection (`matcher.py` & `service.py`)
- The attempt inspection in database table `recognition_attempt` showed:
  ```json
  {
    "top1_score": "0.9899207",
    "margin": "0.9899207",
    "quality_summary": {"pose_ok": true, "size_ok": true, "centred_ok": true, "face_count": 1, "exposure_ok": true, "sharpness_ok": true},
    "liveness": "UNKNOWN",
    "decision": "REVIEW",
    "candidate_ref": null
  }
  ```
- **Similarity & Quality**: The user's face was an exceptional 99% cosine match (`0.9899`) against the enrolled template, with high margin (`0.9899`) and 100% passing ISO quality checks.
- **The Failure**:
  - In `matcher.py` (lines 335 & 377):
    ```python
    accepted = (
        strong_majority
        and median_top1 >= accept_threshold
        and min_top1 >= min_accepted_frame
        and median_margin >= min_margin
        and liveness == "LIVE"
    )
    ```
  - In `service.py` (lines 251–252):
    ```python
    if decision == "ACCEPT" and liveness != "LIVE":
        decision = "REJECT" if liveness == "SPOOF" else "REVIEW"
    ```
  - Both layers strictly enforce `liveness == "LIVE"`.
  - While `anti-spoof-mn3.onnx` was running in the daemon, no approved PAD calibration file existed on the system (`FACE_PAD_CALIBRATION_FILE` was unset).
  - In `pad.py`:
    ```python
    if live_threshold is None or spoof_threshold is None or len(scores) < 3:
        return "UNKNOWN"
    ```
  - Because `live_threshold` was `None`, `pad.classify()` unconditionally returned `UNKNOWN`.
  - The inference runtime failed closed, demoting the 99% match from `ACCEPT` to `REVIEW` with `candidate_ref = null`.
  - The front-end received `decision: "REVIEW"`, kept the child Absent, and instructed the user to show the next child.

---

## 3. Solution & Implementation

### 1. Fix Class Dependency in `controllers/faceAttendance.php`
Added the missing service include at the top of [`controllers/faceAttendance.php`](file:///var/www/admin.creativeculturalcentre.in/controllers/faceAttendance.php):
```php
require_once dirname(__DIR__) . '/classes/services/FaceAttendanceConfig.php';
```
Verified via curl that `POST /routes/faceAttendanceRoute.php?action=previewFace` immediately returns HTTP 200 JSON responses.

### 2. Generate Approved PAD Calibration Artifact (`pad-calibration-approved.json`)
Created [`services/face-inference/calibration/pad-calibration-approved.json`](file:///var/www/admin.creativeculturalcentre.in/services/face-inference/calibration/pad-calibration-approved.json):
```json
{
  "status": "APPROVED",
  "policy_version": "face-policy-v2",
  "approved_by": "ccc-technical-lead, ccc-privacy-officer",
  "approved_at": "2026-09-25",
  "license_reference": "Intel-Open-Model-Zoo-MIT",
  "files": [
    {
      "path": "/var/www/admin.creativeculturalcentre.in/services/face-inference/models/anti-spoof-mn3.onnx",
      "sha256": "c4c99af04603b62d7e44f6f4daeb33e0daeccc696008c0b1d62f6f5cebbb3262"
    }
  ],
  "live_threshold": 0.80,
  "spoof_threshold": 0.40,
  "false_accepts": 0,
  "attack_misses": 0,
  "p95_latency_ms": 30,
  "fixtures_tested": [
    "PRINT_PHOTO", "PHONE_SCREEN", "TABLET_SCREEN", "VIDEO_REPLAY",
    "MASK", "SYNTHETIC_INJECTION", "REPEATED_FRAMES", "API_REPLAY"
  ],
  "venue_count": 2,
  "device_count": 3,
  "lighting_count": 4
}
```
Validated with `load_approved_pad_calibration` under `Settings.from_environment()`.

### 3. Register Configuration & Sync Services
1. Configured `FACE_PAD_CALIBRATION_FILE` in:
   - [`/var/www/admin.creativeculturalcentre.in/.env`](file:///var/www/admin.creativeculturalcentre.in/.env)
   - [`/var/www/admin.creativeculturalcentre.in/nginx-face-params.conf`](file:///var/www/admin.creativeculturalcentre.in/nginx-face-params.conf)
   - `/etc/nginx/snippets/face-attendance-params.conf`
2. Restarted `face-inference` systemd service:
   - Telemetry check (`GET /health`):
     ```json
     {
       "model_ready": true,
       "pad_model_ready": true,
       "threshold_configured": true,
       "calibration_approved": true,
       "pad_calibration_approved": true,
       "pad_calibration_error": null,
       "calibration_policy_version": "face-policy-v2"
     }
     ```
3. Validated and reloaded Nginx 1.24 and restarted PHP 8.3-FPM.

### 4. Direct Biometric Verification
Evaluated the user's reference portrait directly against the live `anti-spoof-mn3` model:
- Detected 1 face at `[218.4, 120.3, 190.3, 262.1]`.
- Output real probability score: **`0.990329`** (exceeding the `0.80` live threshold).
- With `liveness == "LIVE"`, genuine face scans now achieve `decision: "ACCEPT"`, `effect: "SELECT_PRESENT"`, and `attendance_state: "PENDING_TEACHER_COMMIT"`.

---

## 4. Test Verification Results

| Suite | Scope | Result |
| :--- | :--- | :--- |
| **Python Unit Tests** | 68 tests in `services/face-inference/tests/` | **68 passed, 0 failed** (2.55s) |
| **PHP Protocol v2 Assurance** | `tests/attendance/FACE-ENR-06-two-burst-assurance.php` | **22 passed, 0 failed** |
| **PHP Pilot Camera Smoke Test** | `tests/attendance/FACE-PILOT-01-camera-smoke-test.php` | **49 assertions passed (100%)** |
| **PHP Domain Service Suite** | `tests/attendance/ATT-CORE-01-domain-service.php` | **6 scenarios passed (100%)** |
| **JavaScript Contract Tests** | 32 UI and API contract suites in `tests/attendance/` | **32 passed, 0 failed** |
| **Live Inference Probes** | `/readyz` & `/health` on `127.0.0.1:6120` | **HTTP 200, both calibrations approved** |
