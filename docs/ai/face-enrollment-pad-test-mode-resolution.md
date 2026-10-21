# Incident & Architectural Resolution: Reference Onboarding PAD Evidence Decoupling & Test-Mode Enablement

- **Date**: 2026-09-29
- **Components**:
  - Frontend: `/pf/attendance.php`, `/pf/js/controllers/batchAttendanceController.js`
  - Routes: `/routes/faceEnrollmentRoute.php?action=onboardReference`
  - Controllers: `controllers/faceEnrollment.php`
  - Domain Service: `classes/services/FaceEnrollmentService.php`
  - Inference Microservice: `services/face-inference/app/matcher.py`, `services/face-inference/app/pad.py`
  - Database: `ccc_database.attendance_settings`, `ccc_database_test.attendance_settings`
- **Test Contracts**: `tests/attendance/FACE-ENR-06-two-burst-assurance.php`, `services/face-inference/tests/`
- **Status**: **RESOLVED & VERIFIED IN PRODUCTION TEST ENVIRONMENT**

---

## 1. Problem Description & User Symptoms

When staff/admin opened the Face Attendance modal on `/pf/attendance.php` for test students (e.g. `FaceTest01 Child`, member 616, contact 774, branch 2, batch 27):
1. The camera initialized and the green FaceGuide oval tracked the face correctly with affirmative UI status:
   `"Face ready — hold still and tap Capture"`.
2. When the user tapped **"Capture"**, the interface appeared to freeze or reset back to camera preview without taking any enrollment burst frames.
3. The user reported: *"model detects the face but it not capturing the face"*.
4. Systemd journal logs for `face-inference.service` revealed continuous rejections on reference encoding:
   ```json
   {"event":"INFERENCE_REJECTED","timestamp":1790698337,"reason":"REFERENCE_QUALITY_REJECTED","stage":"ENROLLMENT_VALIDATION","reason_code":"REFERENCE_PAD_SPOOF"}
   ```
5. The response returned HTTP 400 with message:
   `"REFERENCE_PAD_SPOOF: Liveness check rejected reference portrait (score: 0.0338, threshold: 0.4000)"`.
6. Because reference onboarding failed at step 1, the client never reached step 2 (Burst A: 5 frames, Burst B: 3 frames), completely halting the enrollment workflow.

---

## 2. Root Cause Analysis

A full investigation of the capture, transport, inference, and domain validation paths identified four separate contributing factors:

### A. Passive Anti-Spoofing Sensitivity to Desktop/Laptop Screen Reflection
- The passive liveness model (`anti-spoof-mn3.onnx`) is a MobileNetV3 binary classifier trained on the CelebA-Spoof dataset to identify digital screen replays and 2D printed photographs.
- In desktop/laptop test environments, users sit directly in front of an illuminated computer display. The diffuse bluish light reflection from the screen onto the face is a key feature that `anti-spoof-mn3` attributes to screen presentation attacks.
- Consequently, bona fide live faces evaluated from webcam feeds in front of a monitor scored between `0.0258` and `0.0669`, well below the approved spoof threshold of `0.4000`.

### B. Premature Exception Throw in Python Inference Layer
- In `services/face-inference/app/matcher.py:encode_reference()`, line 832 checked:
  ```python
  if consensus_pad is not None and self.settings.pad_spoof_threshold is not None and consensus_pad <= self.settings.pad_spoof_threshold:
      raise MatcherUnavailable(
          f"REFERENCE_PAD_SPOOF: Liveness check rejected reference portrait (score: {consensus_pad:.4f}, threshold: {self.settings.pad_spoof_threshold:.4f})"
      )
  ```
- **Architectural Violation**: The inference layer is responsible for extracting biometric measurements and classifying raw evidence. Authorization and testing policies belong in the PHP domain service (`FaceEnrollmentService.php`).
- Raising `MatcherUnavailable` in `matcher.py` prevented lines 852–864 from populating the structured `pad` evidence dictionary (`state: "SPOOF"`, `score: consensus_pad`), causing an uncaught HTTP 400 error that bypassed all PHP domain logic.

### C. Missing Parameter Propagation in Controller Layer
- In `controllers/faceEnrollment.php`, `$allowUncalibratedTesting` was calculated on line 60 via:
  ```php
  $allowUncalibratedTesting = $this->allowUncalibratedTesting($branchId);
  ```
- While `$allowUncalibratedTesting` was forwarded to `$request['allow_uncalibrated_testing']` in action `enrollFace`, it was **omitted entirely** from action `onboardReference`.

### D. Missing Test-Mode Bypass in Domain Service Layer
- In `classes/services/FaceEnrollmentService.php:onboardReference()`, lines 300–321 unconditionally rejected reference portraits whenever `$pad['state'] !== 'LIVE'`.
- Unlike the two-burst enrollment validator `validateInferenceResult()` (which supports `$allowUncalibratedTesting`), `onboardReference()` lacked any branch/test-mode check.

### E. Database Configuration State
- In MySQL `ccc_database.attendance_settings` and `ccc_database_test.attendance_settings`, the row for Branch 2 (`bid = 2`, the designated testing branch) had `face_enrollment_uncalibrated_test_mode = 0`.

---

## 3. Changes Implemented

### 1. Decoupled PAD Evidence from Inference Exceptions (`matcher.py`)
- **File**: [`services/face-inference/app/matcher.py`](file:///var/www/admin.creativeculturalcentre.in/services/face-inference/app/matcher.py#L828-L865)
- Removed lines 832–835 which raised `MatcherUnavailable(REFERENCE_PAD_SPOOF...)`.
- `matcher.py:encode_reference()` now always returns the complete result dictionary with:
  ```python
  pad_state = "UNKNOWN"
  if consensus_pad is not None and self.settings.pad_spoof_threshold is not None and consensus_pad <= self.settings.pad_spoof_threshold:
      pad_state = "SPOOF"
  elif consensus_pad is not None and self.settings.pad_live_threshold is not None and consensus_pad >= self.settings.pad_live_threshold:
      pad_state = "LIVE"
  elif consensus_pad is not None and self.settings.pad_live_threshold is not None and self.settings.pad_spoof_threshold is not None:
      pad_state = "CHALLENGE"

  pad = {
      "state": pad_state,
      "score": round(consensus_pad, 4) if consensus_pad is not None else None,
      "samples": [round(s, 4) for s in pad_scores],
  }
  ```
- **Benefit**: The Python microservice remains purely an evidence provider, returning HTTP 200 with structured measurements.

### 2. Forwarded Test Mode Flag in Controller (`controllers/faceEnrollment.php`)
- **File**: [`controllers/faceEnrollment.php`](file:///var/www/admin.creativeculturalcentre.in/controllers/faceEnrollment.php#L129-L141)
- Added `$request['allow_uncalibrated_testing'] = $allowUncalibratedTesting;` in action `onboardReference` prior to calling `$service->onboardReference($request)`.

### 3. Enabled Test-Mode Reference Onboarding in Domain Service (`FaceEnrollmentService.php`)
- **File**: [`classes/services/FaceEnrollmentService.php`](file:///var/www/admin.creativeculturalcentre.in/classes/services/FaceEnrollmentService.php#L297-L335)
- Updated `onboardReference()`:
  ```php
  $uncalibratedTesting = !empty($command['allow_uncalibrated_testing']);
  if (!$uncalibratedTesting) {
      if (($pad['state'] ?? '') === 'UNKNOWN') {
          FaceErrorLogger::record('REFERENCE_VALIDATION_REJECTED', [
              'member_id' => $memberId, 'contact_id' => $contactId, 'stage' => 'PAD',
              'reason' => 'PAD_CALIBRATION_REQUIRED', 'pad_state' => 'UNKNOWN', 'pad_score' => $pad['score'] ?? null,
          ]);
          return $this->rejected('PAD_CALIBRATION_REQUIRED', 'Liveness calibration is not approved for the current preprocessing version', ['pad' => $pad]);
      }
      if (($pad['state'] ?? '') !== 'LIVE') {
          FaceErrorLogger::record('REFERENCE_VALIDATION_REJECTED', [
              'member_id' => $memberId, 'contact_id' => $contactId, 'stage' => 'PAD',
              'reason' => 'PAD_REJECTED', 'pad_state' => $pad['state'] ?? 'NOT_RUN', 'pad_score' => $pad['score'] ?? null,
          ]);
          return $this->rejected('PAD_REJECTED', 'Reference portrait failed passive liveness detection', ['pad' => $pad]);
      }
  } else {
      $quality['enrollment_mode'] = 'UNCALIBRATED_TEST';
      FaceErrorLogger::record('REFERENCE_UNCALIBRATED_TEST_ONBOARDED', [
          'member_id' => $memberId, 'contact_id' => $contactId,
          'pad_state' => $pad['state'] ?? 'NOT_RUN', 'pad_score' => $pad['score'] ?? null,
      ]);
  }
  ```
- **Fail-Closed Security Guarantee**: Production branches (`allow_uncalibrated_testing = false`) strictly enforce `LIVE` PAD. Only test branches with the flag enabled can onboard reference portraits in `UNCALIBRATED_TEST` mode.

### 4. Database Test Flag Activated for Branch 2
- In `attendance_settings` (`ccc_database` and `ccc_database_test`), set:
  ```sql
  UPDATE attendance_settings SET face_enrollment_uncalibrated_test_mode = 1 WHERE bid = 2;
  ```
- Now, all requests targeting Branch 2 automatically resolve `$allowUncalibratedTesting = true`.

### 5. Automated Regression Test Coverage Added
- **File**: [`tests/attendance/FACE-ENR-06-two-burst-assurance.php`](file:///var/www/admin.creativeculturalcentre.in/tests/attendance/FACE-ENR-06-two-burst-assurance.php#L510-L533)
- Added scenario:
  `FACE-ENR-06 allows reference onboarding in uncalibrated test mode when PAD is unapproved or borderline`.
- Verifies that:
  - When PAD state is `SPOOF` (`score: 0.05`), setting `allow_uncalibrated_testing => true` produces `state => 'ACTIVE'` and persists the reference portrait in the database with status `PENDING_ENROLLMENT`.
  - When `allow_uncalibrated_testing` is false, it continues to return `state => 'REJECTED'` and `reason => 'PAD_REJECTED'`.

---

## 4. Verification & Operational Health

### Test Suites Execution
1. **Python Unit & Contract Tests**:
   - Command: `/var/lib/creativeculturalcentre/face-inference/venv/bin/python -m unittest discover -s services/face-inference/tests`
   - Result: **89/89 PASS** (0 failures, 0 errors in 2.45s).
2. **PHP Two-Burst Assurance Contract**:
   - Command: `php tests/attendance/FACE-ENR-06-two-burst-assurance.php`
   - Result: **26/26 PASS** (0 failures).
3. **PHP Scan Lifecycle & Sidecar Contract**:
   - Command: `php tests/attendance/FACE-API-01-scan-lifecycle.php`
   - Result: **16/16 PASS** (0 failures).
4. **JavaScript UI, Camera, & State Machine Contracts**:
   - Command: `for f in tests/attendance/*.js; do node "$f"; done`
   - Result: **35/35 test suites PASS** (all camera, UI, and enrollment contracts green).
5. **Systemd Daemon Status**:
   - `systemctl restart face-inference` executed successfully.
   - `curl -s http://127.0.0.1:6120/health` reports:
     `model_ready: true`, `pad_model_ready: true`, `pad_calibration_approved: true`, `memory: 143 MB RSS`.

---

## 5. Summary of Active Testing Settings

| Parameter | Configuration |
|---|---|
| **Branch** | **Branch 2** (Skating testing branch) |
| **Batch** | **Batch 27** (*Skating Testing Thur 03:00 Pm*) |
| **Test Members** | Contact IDs 774–778 (`FaceTest01 Child` to `FaceTest05 Child`) |
| **Face Attendance Enabled** | `1` (Active) |
| **Shadow Mode** | `1` (Active: face scan selects student row, but teacher must click "Punch Attendance" to commit to DB) |
| **Live Write** | `0` (Disabled: no autonomous DB writes without teacher action) |
| **Enrollment Mode** | `1` (Active) |
| **Uncalibrated Test Mode** | `1` for Branch 2 (Allows enrollment under webcam lighting) |
| **AI Model Version** | `2`: `opencv-zoo-yunet-sface-int8-omz-anti-spoof-mn3-opencv-preprocess-v2` |
| **Thresholds** | Reference match $\ge 0.90$, Burst Holdout Median $\ge 0.90$, Holdout Min $\ge 0.85$ |
