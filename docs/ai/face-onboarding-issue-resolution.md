# Incident & Resolution: Face Onboarding Reference Capture & Contract Gating

- **Date**: 2026-09-26
- **Component**: Face Attendance & Enrollment (Protocol v2)
- **Affected Endpoints**: `/routes/faceEnrollmentRoute.php?action=previewEnrollment`, `/routes/faceEnrollmentRoute.php?action=onboardReference`, `/pf/attendance.php`
- **Status**: Resolved & Verified

---

## 1. Issue Description

When attempting to enroll or onboard a child's face (`FaceTest01 Child`) on both Desktop and Mobile browsers:

1. The camera successfully loaded and detected the user's face, displaying a solid green guide oval (`.is-ready`).
2. The UI gave affirmative feedback:
   - Green banner below the video: `"Face ready — hold still and tap Capture."`
   - Top-right status pill badge: `"Ready to capture"`.
3. The staff member checked the confirmation checkbox:
   - `[x] Staff confirmation: I verify this is the reference identity portrait for FaceTest01 Child`.
4. **Failure State**: The large green `[ Capture ]` button below the camera remained disabled (olive green / greyed out) and completely unclickable.
5. In browser developer tools, continuous polling calls to `previewEnrollment` returned:
   ```json
   {
     "api_version": "v1",
     "state": "PREVIEW",
     "face_count": 1,
     "quality": {
       "sharpness_ok": true,
       "exposure_ok": true,
       "pose_ok": true,
       "size_ok": true,
       "centred_ok": true
     },
     "capture_state": "UNAVAILABLE",
     "reference_match_state": "NO_REFERENCE",
     "duplicate_band": "UNAVAILABLE"
   }
   ```
   Users were unable to capture or onboard faces and perceived the system as broken.

---

## 2. Root Cause Analysis

### A. Gating Logic in UI (`pf/attendance.php`)
Line 973 previously had:
```html
ng-disabled="faceEnrollmentCaptureState != 'CLEAR' || !faceEnrollmentHasReference"
```
Under Face Protocol v2, every child must have a trusted Verified Reference Portrait before enrollment templates can be created. Because `FaceTest01 Child` had no reference yet:
- `!faceEnrollmentHasReference` evaluated to `true`.
- The server preview returned `capture_state: "UNAVAILABLE"` because there was no reference template to compare against.
- Thus, the button was unconditionally disabled.

### B. Disconnected User Journey
The initial design placed a separate blue button in a yellow callout box:
```html
<button ng-click="onboardReferencePortrait()">Onboard Reference Portrait</button>
```
- On desktop, having two different capture buttons (`Onboard Reference Portrait` vs `Capture`) was confusing when the camera feedback explicitly told the user to "tap Capture".
- On mobile screens, the yellow card was pushed below the fold, making the blue button invisible without scrolling.

### C. Backend Contract Mismatch (`services/face-inference/app/matcher.py` & `FaceEnrollmentService.php`)
When the blue button was clicked:
1. `matcher.py`'s `encode_reference` endpoint returned `{"quality_evidence": {...}, "pad_score": 0.99}` without a top-level `quality` dictionary and without a `pad` dictionary containing `state: "LIVE"`.
2. `FaceEnrollmentService.php` checked:
   ```php
   $quality = $encoded['quality'] ?? [];
   if (empty($quality['pose_ok'])) {
       return $this->rejected('QUALITY_FAILED', 'Reference portrait does not meet quality requirements');
   }
   ```
   Because `$quality` was empty, reference onboarding was rejected with `REFERENCE_QUALITY_FAILED`.

### D. Guidance Message Disconnect
The video detection loop in `batchAttendanceController.js` set:
```javascript
$scope.faceEnrollmentDetectionMessage = 'Face ready — hold still and tap Capture.';
```
even when the child still required staff confirmation and reference verification, giving misleading instructions while the button was disabled.

---

## 3. Changes Implemented

### 1. Unified Single-Action Capture Button (`pf/attendance.php`)
- Replaced the hard-disabled logic with:
  ```html
  <button type="button" class="btn btn-success"
      ng-if="faceEnrollmentState == 'READY' && faceEnrollmentDetectionReady && (!faceEnrollmentDetectorEnforced || faceEnrollmentDetected) && !faceEnrollmentIssue.visible"
      ng-disabled="(faceEnrollmentCaptureState != 'CLEAR' && faceEnrollmentHasReference) || (!faceEnrollmentHasReference && !faceEnrollmentStaffConfirmed) || faceEnrollmentReferenceBusy"
      ng-click="captureFaceEnrollment()">
      <i class="fa fa-spinner fa-spin" ng-if="faceEnrollmentReferenceBusy"></i>
      {{faceEnrollmentReferenceBusy ? 'Verifying Reference…' : (faceEnrollmentHasReference ? 'Capture' : 'Capture & Verify Reference')}}
  </button>
  ```
- If the child has no reference, checking the staff confirmation checkbox immediately enables the button with the label **`Capture & Verify Reference`**.

### 2. Cascading Two-Burst Capture Controller (`pf/js/controllers/batchAttendanceController.js`)
- Updated `captureFaceEnrollment()`:
  - If `!$scope.faceEnrollmentHasReference`: automatically invokes `onboardReferencePortrait(callback)`.
  - Upon successful verification and reference activation, seamlessly transitions into the Two-Burst enrollment sequence (Burst A $\to$ 750ms physical separation $\to$ Burst B $\to$ Holdout & Consensus $\to$ Save).
- Updated `onboardReferencePortrait()`:
  - Accepts a completion callback `onSuccess(success, errorMsg)`.
  - Sets `faceEnrollmentReferenceBusy = true` while uploading, providing a live spinner.
  - Sends valid session credentials and device headers.

### 3. Dynamic Camera Guidance Messaging
- Updated `detectEnrollmentFace()` and `applyEnrollmentModelDetection()`:
  - If face is aligned but staff confirmation is not checked:
    `"Face ready — check Staff Confirmation below to enable capture."`
  - Once checked:
    `"Face ready — hold still and tap Capture."`

### 4. Inference Contract Alignment (`services/face-inference/app/matcher.py`)
- Updated `encode_reference` in `OnnxFaceMatcher` to return:
  ```python
  quality = {
      "sharpness_ok": bool(evidence.get("sharpness_ok", False)),
      "exposure_ok": bool(evidence.get("exposure_ok", False)),
      "pose_ok": bool(evidence.get("facing_camera", False) and evidence.get("profile_ok", False)),
      "size_ok": bool(evidence.get("size_ok", False)),
      "centred_ok": bool(evidence.get("centred_ok", False)),
      "reasons": evidence.get("reasons", []),
  }
  pad = {
      "state": "LIVE" if pad_score > pad_spoof_threshold else "SPOOF",
      "score": round(pad_score, 4),
  }
  ```

### 5. Backend Service Fallbacks (`FaceEnrollmentService.php` & `routes/faceEnrollmentRoute.php`)
- Added fallbacks in `FaceEnrollmentService.php` for `quality` from `quality_evidence` and normalized `pose_ok`.
- Added session cookie fallback (`$_COOKIE[session_name()]`) in `routes/faceEnrollmentRoute.php` for `multipart/form-data` uploads.

### 6. AngularJS Child Scope Isolation Fix (`pf/attendance.php` & `batchAttendanceController.js`)
- **Root Cause**: The checkbox `<input type="checkbox" ng-model="faceEnrollmentStaffConfirmed">` was originally inside `<div class="alert alert-warning" ng-if="...">`. In AngularJS 1.x, `ng-if` creates a prototypal child scope. Checking the box created a shadowed property on the child scope, leaving `$scope.faceEnrollmentStaffConfirmed` on the parent controller false. Consequently, the Capture button (outside the `ng-if`) and the controller detection loop remained in the unconfirmed state while only the inner blue button saw `true`.
- **Resolution**:
  - Replaced `ng-if` with `ng-show` on the reference portrait warning container, preventing child scope creation.
  - Bound the checkbox using dot notation: `ng-model="faceEnrollmentForm.staffConfirmed"` and added `ng-change="toggleStaffConfirmation()"`.
  - Added `isStaffConfirmed()` helper on `$scope` to ensure reliable checks across both template expressions and controller logic.

---

## 4. Verification

1. **Python Unit Tests**:
   - `PYTHONPATH=services/face-inference /var/lib/creativeculturalcentre/face-inference/venv/bin/python -m unittest discover -s services/face-inference/tests -p "test_*.py"`
   - Result: **68 tests passed, 0 failures** in 2.26s.
2. **PHP Protocol v2 Assurance Suite**:
   - `php tests/attendance/FACE-ENR-06-two-burst-assurance.php`
   - Result: **22 scenarios passed, 0 failures**.
3. **Runtime Health**:
   - `curl -s http://127.0.0.1:6120/readyz`
   - `{"status":"ready","model_ready":true,"model_integrity_verified":true}`
4. **Git Commits**:
   - Commit `eb9c294c`: `"fix(face-enrollment): seamless reference onboarding and capture flow"`
   - Commit `b692ca54`: `"fix(face-enrollment): prevent AngularJS child scope isolation on staff confirmation"`
