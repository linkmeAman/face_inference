# Incident & Resolution: Face Scanning Latency, Scanner State Machine Recovery & Enrollment Quality Tolerance

- **Date**: 2026-09-28
- **Component**: Face Attendance & Enrollment (Protocol v2)
- **Affected Endpoints**: `/pf/attendance.php`, `/pf/js/controllers/batchAttendanceController.js`, `/services/face-inference/app/quality.py`, `/services/face-inference/app/matcher.py`, `/routes/faceAttendanceRoute.php`, `/routes/faceEnrollmentRoute.php`
- **Status**: Capture/selection resilience implemented; PAD field approval pending recollection with preprocessing version `anti-spoof-mn3-square-1.5-v1`.

---

## 1. Issue Description

Users in the field reported two major operational hurdles while performing face enrollment and live attendance scanning:

1. **Member Enrollment False Rejections**:
   - When attempting to onboard a student's reference portrait, the user comfortably framed their face inside the on-screen green oval guide.
   - The backend rejected the capture with:
     ```
     REFERENCE_QUALITY_FAILED: FACE_OFF_CENTRE, FACE_TOO_LOW
     REFERENCE_QUALITY_FAILED: FACE_OUTSIDE_GUIDE
     ```
   - The user was unable to proceed and was shown raw exception text with no actionable retry button, forcing them to cancel back to the student roster.

2. **Attendance Scanner Freezes ("Page Gets Stuck")**:
   - While conducting live attendance scanning, the scanner would periodically freeze and stop responding to incoming faces.
   - Subsequent face presentations were ignored, leaving the student unmarked (`0 present`).
   - Clicking **"Finish Scanning"** hung indefinitely with the message *"Finishing the current face check…"*, forcing staff to reload the browser and restart the entire attendance workflow from the beginning.

3. **High Scanning Latency**:
   - The recognition process took noticeably too long (>2.5 seconds per child), resulting in classroom queue delays.

---

## 2. Root Cause Analysis

### A. Guide Boundary & Center Tolerance Disconnect (`quality.py`)
- The front-end oval reticle on `#faceEnrollmentPreview` extends vertically from $y = 0.10$ to $y = 0.82$ with normalized center at $y = 0.46$ and semi-axes $R_x = 0.28, R_y = 0.36$.
- When real adults and children positioned their faces naturally within the visual guide, the chin reached a normalized ellipse distance $d \approx 1.02$, and natural head posture placed the center offset $\Delta y \approx 0.11$.
- However, the backend validation in `services/face-inference/app/quality.py` strictly enforced:
  - `_ellipse_dist <= 0.96` (causing `FACE_OUTSIDE_GUIDE`)
  - `offset_y <= 0.09` (causing `FACE_TOO_LOW`)
  - `eye_level <= 0.48`
- As a result, faces that were perfectly aligned with the visual guide on-screen were systematically rejected by the backend.

### B. State Machine Deadlock on Transient Backend Responses (`batchAttendanceController.js`)
- In `batchAttendanceController.js:captureFaceAttempt()`, when a scan attempt received a transient response (such as `INFERENCE_BUSY`, `INFERENCE_TIMEOUT`, or `SCAN_BUSY`), the retry handler scheduled a retry using `scheduleFaceAttempt()`.
- However, the code **omitted calling `faceMachine.resume()`** on these transient error branches.
- Consequently:
  - `faceMachine` remained permanently locked in the `PROCESSING` state with `inFlight = true`.
  - On every subsequent cycle, `faceMachine.canCapture()` returned `false`, dropping all future capture triggers silently.
  - When the teacher clicked **"Finish Scanning"**, `completeFaceScan()` checked `if (faceMachine && faceMachine.inFlight)`, which was permanently `true`, causing it to loop indefinitely waiting for `inFlight` to clear.

### C. Resource Contention on Python Inference Lock
- The Python inference daemon (`main.py`) runs a single-threaded server where inference endpoints serialize requests under a shared mutex (`_inference_lock`).
- The browser was continuing to poll `/preview` every 600ms even while a multi-frame recognition attempt was in progress (`CAPTURING` and `PROCESSING` states).
- This continuous preview polling directly competed with the 3-frame recognition batch for the inference lock, artificially inflating recognition latency.

### D. Inter-Frame Capture Pauses and Queue Hand-Off Delays
- In `captureFaceAttempt()`, the browser introduced an artificial `250ms` delay between consecutive captured frames in the 3-frame burst ($250\text{ms} \times 2 = 500\text{ms}$).
- In continuous scanning mode, `waitForNextFace` strictly blocked until `faceAttendanceFaceCount === 0`. When students lined up closely in queue, the camera frame rarely cleared to zero faces, stalling the scanner indefinitely between children.

---

## 3. Implementation Details

### A. Calibrated Quality Tolerances & Client-Server Synchronization
1. **Backend Bounds Calibration (`services/face-inference/app/quality.py`)**:
   - Expanded perimeter boundary threshold: relaxed `_ellipse_dist` limit from `0.96` to `1.05`, properly aligning backend validation with the visible oval reticle ($R=1.00$).
   - Relaxed vertical centering offset from `0.09` to `0.13` and horizontal offset from `0.09` to `0.11`.
   - Expanded acceptable eye-level range from `[0.26, 0.48]` to `[0.24, 0.52]`.
2. **Client-Side Synchronization (`pf/js/controllers/batchAttendanceController.js`)**:
   - Updated `evaluateFacePoseAndBoundary()` to match backend parameters (`offsetX <= 0.11`, `offsetY <= 0.13`, `perimeterOk <= 1.05`, `eyeLevel: 0.24 - 0.52`).

### B. State Machine Deadlock Elimination & Fallback Recovery
1. **Guaranteed State Machine Resume**:
   - In `batchAttendanceController.js:captureFaceAttempt()`, added:
     ```javascript
     if (faceMachine && faceMachine.state !== 'SCANNING') {
       faceMachine.resume();
     }
     ```
     to all retry, timeout, and transient error exit paths.
2. **Fallback Recovery in Scan Completion**:
   - Added a `1200ms` fallback timeout in `completeFaceScan()`:
     ```javascript
     setTimeout(function() {
       if (scope.faceAttendanceCompleting) {
         finishFaceScan(scope);
       }
     }, 1200);
     ```
     guaranteeing that teachers can always exit or finish scanning even under unexpected network lag.

### C. Scanning Latency Optimization
1. **Inference Lock Deconfliction**:
   - In `pollFaceModelPreview()`, preview polling is now paused during both `CAPTURING` and `PROCESSING` states, giving 100% of inference lock time to the recognition pipeline.
2. **Reduced Inter-Frame Capture Delay**:
   - Reduced burst frame capture delay from `250ms` to `140ms`, shaving >220ms off each recognition attempt.
3. **Queue Hand-Off Timeout**:
   - In `waitForNextFace()`, added a `1600ms` timeout (`faceWaitStartTime`). If queued students are standing close together and the frame does not achieve `faceAttendanceFaceCount === 0`, scanning automatically proceeds once the timeout elapses.

### D. Human-Centric Error Guidance & Immediate Retry
1. **Actionable Error Formatting**:
   - Added `formatFaceQualityError()` in `batchAttendanceController.js` to translate raw technical codes (e.g. `FACE_TOO_LOW`, `FACE_OFF_CENTRE`, `FACE_OUTSIDE_GUIDE`) into plain guidance:
     *"Face is slightly too low. Move face up into the guide and tap Capture."*
2. **Non-Destructive Retry Button (`pf/attendance.php`)**:
   - Added a **`[ Try Again ]`** button when `faceEnrollmentState === 'ERROR'`, allowing staff to immediately re-capture without exiting the modal or losing roster context.
   - Stale error banners automatically clear when the face is re-aligned inside the guide.

---

## 4. Verification & Testing

1. **Systemd Service**:
   - `systemctl restart face-inference.service` completed cleanly.
   - Probe `http://127.0.0.1:6120/health` verified active and healthy.

2. **JavaScript Contract Test Suites**:
   - Ran all 32 contract test suites in `tests/attendance/*.js`:
     ```
     PASS ATT-FIX-02 browser unlimited roster contract
     PASS face-api-session-contract
     PASS FACE-API-03 punch-in flow contract
     PASS FACE-API-04 selected-session roster contract
     PASS FACE-API-05 non-session batch contract
     PASS FACE-API-07 transient inference recovery contract
     PASS FACE-CAM-01 single-control dual-camera contract
     PASS FACE-ENR-UI-01 progressive mapping contract
     PASS FACE-ENR-UI-02 guided detection contract
     PASS FACE-ENR-UI-03 model preview contract
     PASS overlay-coordinate-contract
     PASS FACE-ENR-UI-05 readiness recovery contract
     PASS FACE-ENR-UI-06 focused-roster mapping state contract
     PASS FACE-ENR-UI-07 automatic enrollment contract
     PASS FACE-ENR-UI-08 persistent roster status contract
     PASS FACE-ENR-UI-09 cross-member duplicate recovery contract
     PASS FACE-ENR-UI-10 progressive setup contract
     PASS FACE-ENR-UI-11 concise assignment warning contract
     PASS FACE-OUT-01 departure scan contract
     PASS FACE-PAD-01 active challenge UI contract
     PASS FACE-UI-01 state machine contract
     PASS FACE-UI-02 punch-in detection overlay contract
     PASS FACE-UI-03 non-blocking continuous scan contract
     PASS FACE-UI-04 cancel rollback contract
     PASS FACE-UI-05 selection and restart recovery contract
     PASS FACE-UI-06 non-blocking recognized-child flow contract
     PASS FACE-UI-07 stop-during-processing contract
     PASS FACE-UI-08 uncertain-face automatic continuation contract
     PASS FACE-UI-09 next-child gate contract
     PASS FACE-UI-10 concise guided recognition contract
     PASS FACE-UI-12 no-scan-is-absent staged contract
     PASS FACE-UI-13 staged workflow contract
     ALL JS CONTRACT TESTS PASSED
     ```

3. **PHP Camera Smoke Test Suite**:
   - `php tests/attendance/FACE-PILOT-01-camera-smoke-test.php`: **49 / 49 assertions passed (100%)**.

4. **PHP Two-Burst Assurance Suite**:
   - `php tests/attendance/FACE-ENR-06-two-burst-assurance.php`: **22 / 22 scenarios passed (100%)**.

5. **Python Inference Unit Tests**:
   - `/var/lib/creativeculturalcentre/face-inference/venv/bin/python3 -m unittest discover services/face-inference/tests/`: **69 / 69 unit tests passed (100%)**.
