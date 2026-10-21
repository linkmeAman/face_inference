# Incident: Reference Portrait PAD False Rejections & Superseded Mitigation

- **Date**: 2026-09-28
- **Component**: Face Attendance & Enrollment (Protocol v2)
- **Affected Endpoints**: `/pf/attendance.php`, `/pf/js/controllers/batchAttendanceController.js`, `/routes/faceEnrollmentRoute.php?action=onboardReference`, `/internal/reference/encode`, `/services/face-inference/app/matcher.py`, `/services/face-inference/app/pad.py`
- **Status**: Earlier mitigation superseded on 2026-09-28. The active implementation uses FaceGuide-authoritative canvas capture and a centered square `1.5x` PAD crop. The prior unversioned calibration must be recollected before PAD can be approved again.

---

## 1. Issue Description

During student onboarding on `/pf/attendance.php`, staff attempted to enroll a child's reference portrait (specifically `FaceTest02 Child`, contact ID 617, branch 2, batch 27).

1. The child comfortably positioned their face within the green on-screen oval guide reticle.
2. The UI gave affirmative feedback:
   - Guide oval illuminated solid green (`.is-ready`).
   - Status badge showed `"Ready to capture"`.
3. Staff checked the confirmation checkbox:
   - `[x] Staff confirmation: I verify this is the reference identity portrait for FaceTest02 Child`.
4. The user tapped **`[ Capture & Verify Reference ]`**.
5. **Failure State**: The enrollment failed and presented the error:
   ```
   REFERENCE_PAD_SPOOF: Liveness check rejected reference portrait
   ```
6. The issue reproduced across multiple distinct live human subjects under normal indoor lighting conditions.
7. Crucially, the **Live Attendance Scan** flow under identical camera hardware, distance, and ambient lighting succeeded reliably with genuine liveness scores exceeding 0.99.

---

## 2. Root Cause Analysis

A systematic trace of the end-to-end capture and inference pipelines identified three fundamental differences between Reference Onboarding and Attendance Scan:

### A. Single-Shot Vulnerability vs Multi-Frame Resilience
- **Attendance Scan**: Captures 3 frames over 500ms and executes temporal voting (`live_frames >= 2` out of quality frames).
- **Reference Enrollment**: Previously captured exactly **1 single frame** at a low resolution ($640 \times 480$) via HTML `<canvas>`.
- Any momentary trackpad tap vibration, webcam auto-exposure adjustment, or webcam driver noise-reduction (which smooths out fine skin texture and pores) on that single frame dropped its anti-spoof score below the frozen $0.40$ threshold, triggering an immediate and permanent rejection.

### B. Crop Context Starvation (`pad.py`)
- Reference onboarding previously used a tight **10% padding** (`padding=0.10`) around the bounding box returned by YuNet.
- Real human faces have diverse jawline geometries and chin contours. A tight 10% bounding crop cuts off the jawline curve and forehead/hair boundary transitions.
- The `anti-spoof-mn3` model relies heavily on face-to-background edge gradients and 3D facial curvature to distinguish real faces from flat 2D photograph prints or digital screens. Without this context, real faces in tight crops frequently receive suppressed liveness scores.

### C. Display Buffer Compression Artifacts
- The `<canvas>` capture method drew from the preview `<video>` element, which is subject to browser video pipeline downsampling, chromatic sub-sampling ($YUV420$), and canvas re-encoding.

### D. Diagnostic Opacity
- The rejection error string previously returned only:
  ```
  REFERENCE_PAD_SPOOF: Liveness check rejected reference portrait
  ```
  Neither the backend logs nor the user-facing error indicated whether the evaluation was borderline (e.g., score 0.395 vs 0.400) or a severe spoof misclassification (e.g., 0.050).

---

## 3. Changes Implemented

The changes below describe the superseded mitigation. They are retained as incident history; they are not the current capture or PAD preprocessing contract.

### 1. Multi-Frame Burst & Median Consensus (`matcher.py`, `service.py`, `main.py`)
- Updated `/internal/reference/encode` to accept an array of burst frames (`frames`) in addition to legacy single-frame requests.
- Evaluates PAD on all detected faces in the burst.
- Computes temporal median consensus across all valid frame scores:
  $$\text{score}_{\text{consensus}} = \text{median}(\text{pad\_scores})$$
- If $\text{score}_{\text{consensus}} < \text{pad\_threshold}$ (strictly frozen at $0.40$), reference enrollment fails closed.

### 2. Isolated Crop Padding Expansion (`pad.py`)
- Parameterized `pad.real_probability(..., padding=0.10)` to accept custom padding.
- Set `padding=0.20` strictly for Reference Onboarding in [`matcher.py:encode_reference`](file:///var/www/admin.creativeculturalcentre.in/services/face-inference/app/matcher.py#L173), providing the neural net with sufficient jawline and contour context.
- Added coordinate clamping (`_clamp_box`) ensuring 20% expanded crops never exceed frame dimensions.
- Left Attendance Scan padding untouched at `0.10`, guaranteeing **zero regression** on live punch-ins.

### 3. Sharpest-Frame Biometric Extraction (`quality.py`, `matcher.py`)
- Added `sharpness_score(frame, box)` using normalized Laplacian variance on the face region.
- When the 3-frame burst passes PAD consensus, the matcher selects the frame with the highest sharpness for extracting the 128-D SFace embedding, ensuring reference templates have maximum biometric fidelity.

### 4. High-Fidelity Native Browser Capture with Fallback (`batchAttendanceController.js`)
- Updated `onboardReferencePortrait()` to capture a 3-frame burst spaced 120ms apart.
- Utilizes W3C `ImageCapture.grabFrame()` (supported on Chrome/Chromium/Android) at native camera resolution (capped at max $1280 \times 720$) to bypass display-buffer artifacts.
- Automatically falls back to standard `<canvas>` capture on Safari/iOS and browsers lacking `ImageCapture`.

### 5. Multi-Frame Backend Transport (`FaceInferenceClient.php`, `FaceEnrollmentService.php`, `faceEnrollmentRoute.php`)
- `FaceInferenceClient::encodeReference()` supports either a single binary JPEG string or an array of binary JPEG strings, transmitting multipart `frames[]`.
- `FaceEnrollmentService::onboardReference()` accepts the frame array, stores the primary portrait in AES-256-GCM encrypted database storage, and forwards the burst to inference.
- `faceEnrollmentRoute.php` ingests `$_FILES['portraits']` array from FormData.

### 6. Score Telemetry & Human-Centric UI Guidance (`matcher.py`, `batchAttendanceController.js`)
- Rejection errors now explicitly report:
  ```
  REFERENCE_PAD_SPOOF: Liveness check rejected reference portrait (score: 0.3842, threshold: 0.4000)
  ```
- `formatFaceQualityError()` translates `REFERENCE_PAD_SPOOF` into actionable instructions for teachers:
  *"Liveness check could not confirm a live face. Ensure natural front-facing light, avoid bright backlighting or laptop screen reflections, and hold steady."*

---

## 4. Verification & Operational Health

### Automated Test Suites
1. **Python Unit & Contract Tests**:
   - Command: `/var/lib/creativeculturalcentre/face-inference/venv/bin/python -m unittest discover -s services/face-inference/tests`
   - Result: **69 / 69 PASS** (100%).
2. **PHP Pilot Camera Smoke Test**:
   - Command: `php tests/attendance/FACE-PILOT-01-camera-smoke-test.php`
   - Result: **49 / 49 assertions PASS** (100%).
3. **PHP Two-Burst Assurance**:
   - Command: `php tests/attendance/FACE-ENR-06-two-burst-assurance.php`
   - Result: **23 / 23 scenarios PASS** (100%), including multi-frame burst reference onboarding.
4. **JavaScript Contract Tests**:
   - Command: `for test in tests/attendance/*.js; do node "$test"; done`
   - Result: **32 / 32 suites PASS** (100%).

### Resource Footprint
- **New ML Models**: 0 (reused existing in-memory YuNet INT8, SFace INT8, and anti-spoof-mn3).
- **Daemon Memory**: 96.1 MB RSS (systemd limit: 512 MB).
- **Attendance Scan Latency Impact**: 0 ms (isolated to reference enrollment).
