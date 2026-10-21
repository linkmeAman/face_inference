# Batch Attendance Roster & Member Lifecycle: Technical Audit and Remediation

**Document Reference**: `docs/BATCH_ATTENDANCE_ROSTER_AND_LIFECYCLE_AUDIT.md`  
**Date**: 2026-10-16  
**Status**: **IMPLEMENTED & VERIFIED**  
**Target Screen**: `pf/attendance.php`  
**Controller**: `pf/js/controllers/batchAttendanceController.js`  
**Backend Route & Controller**: `routes/memberRoute.php` $\rightarrow$ `controllers/member.php` (`select_batch_member`)  
**Attendance Recording**: `routes/attendanceRoute.php` $\rightarrow$ `controllers/attendance.php` (`insertBatchAttendance`)  
**Related ADR**: [ADR-025 (DECISIONS.md)](DECISIONS.md#adr-025--batch-attendance-empty-roster-glitch-historical-lifecycle--teacher-payroll-preservation)

---

## 1. Executive Summary

When inspecting `pf/attendance.php` for Branch **Vanita Vishram** (`bid = 5`), Class **Judo Vanita Vishram 05:00 Pm (Manisha)** (`batch_id = 10`), on historical date **06-02-2025** (as shown in operator screenshot `docs/images/Screenshot 2026-10-16 at 3.12.18 PM.png`), an anomalous UI state was observed:
1. Both **"Scan Arrivals"** (camera facial recognition) and **"Punch Attendance"** (manual entry) buttons appeared above the roster table.
2. The table header rendered (`NAME | ATTENDANCE | SESSIONS | FACE STATUS`), but the table body was completely empty (zero student rows).
3. The expected empty state banner (`No members found for this class.`) was missing.

A deep architectural investigation across the client database (`ccc_database`), central database (`ccc_central`), and frontend controller revealed **four interconnected issues**:
1. **Frontend Roster State Glitch**: Assigned batch trainers/employees were pushed into the same `$scope.list` array as students with `type: 'E'`. Evaluating `$scope.list.length > 0` caused the UI to treat a roster containing 1 trainer and 0 students as "LOADED", displaying action buttons and table headers over an empty table body (`row.type == 'M'`).
2. **Backend Historical Query Flaw**: `controllers/member.php:700` checked `WHERE m.status IN ('0','1')` on the live `member` table. Four students (`Aastha Mehta`, `Dhruv More`, `Pranshi`, `Saifuddin`) had valid packages covering `06-02-2025`, but because their packages subsequently ended, the daily cron had marked them `status = '2'` (Ex-member). The backend rejected all 4 students on past dates based on their current status today.
3. **Hidden Teacher Payroll Coupling**: `insertBatchAttendance` in `controllers/attendance.php:518` explicitly looks for `type: 'E'` in the submitted contacts array to record teacher class sessions for payroll (`controllers/teacherSalary.php`). Completely stripping the trainer from the punch submission would have broken teacher payroll.
4. **Orphaned Previous-Month Guard**: The frontend compared only `"MM"` (month number) without year (treating October 2025 as the current month) and never bound `$scope.action_disable` to the button's `ng-disabled` attribute in the template.

---

## 2. Root Cause Analysis

### A. Why both "Scan Arrivals" and "Punch Attendance" appear simultaneously
The screen operates under configurable Face Attendance operating modes defined in `classes/services/BrowserFacePolicy.php`:
- `DISABLED`: Face recognition is off; only manual punch is available.
- `CAPTURE_ONLY`: Face enrollment is allowed, but class scanning is off.
- `SHADOW` / `TEST`: **Hybrid Pilot Mode**. Camera scanning is available for testing, while manual punch remains active so staff are never blocked.
- `LIVE`: Camera scanning is mandatory; manual punch is hidden (`browserFaceOperatingMode != 'LIVE'`).

In `pf/attendance.php`:
- `Scan Arrivals` renders when `browserFaceOperatingMode != 'DISABLED' && browserFaceOperatingMode != 'CAPTURE_ONLY'`.
- `Punch Attendance` renders when `browserFaceOperatingMode != 'LIVE'`.
Because active batches default to `SHADOW` mode prior to full biometric rollout, **both buttons are shown by design in hybrid mode**.

### B. Why buttons and table headers appear over an empty roster
In `pf/js/controllers/batchAttendanceController.js`:
```javascript
// Lines 1368-1381: Trainer injected into rows
var rows = [];
employees.forEach(function(employee){
    employee.type = 'E'; // E = Employee / Trainer
    rows.push(employee);
});

// Lines 1392-1402: Students injected into rows
(response.data.data || []).forEach(function(member){
    member.type = 'M'; // M = Member / Student
    rows.push(member);
});

// Lines 1409-1411 (LEGACY BUG):
$scope.list = rows;
$scope.postLoadDivShow = $scope.list.length > 0; // Evaluated to TRUE (1 > 0)!
setRosterState($scope.postLoadDivShow ? 'LOADED' : 'EMPTY'); // Set to 'LOADED'!
```
When `setRosterState('LOADED')` ran, `$scope.attendanceStage` became `'READY'`:
- `<div class="attendance-primary-actions" ng-if="attendanceStage == 'READY'">` $\rightarrow$ rendered action buttons.
- `<div class="roster-empty ... ng-if="rosterState === 'EMPTY'">` $\rightarrow$ hidden because `rosterState` was `'LOADED'`.
- `<table ...>` header rendered because `list.length > 0`.
- In `<tbody>`, `<tr ng-repeat="row in list" ng-if="row.type == 'M'">` rendered 0 rows because the only row was `type == 'E'`.

### C. Why the backend returned 0 students on 06-02-2025
In `controllers/member.php:700`:
```php
$availableResult = $conn->query("SELECT DISTINCT `m`.`contact_id` 
    FROM `member` `m` 
    INNER JOIN `contact` `c` ON `m`.`contact_id` = `c`.`id` 
    WHERE `m`.`status` IN ('0','1') 
      AND `m`.`contact_id` IN ({$contactIdSql}) 
      AND `m`.`park` = '0' AND `c`.`park` = '0'");
```
- In the CCC database, `m.status` values are: `0` (Inactive/Lead), `1` (Active Member), `2` (Ex-member / Expired).
- The daily cron job updates `member.status = '2'` when `CURDATE() > package end_date`.
- On `2025-02-06`, the 4 enrolled students were active and had remaining sessions. However, querying the screen today evaluated the students' **current** status (`2`), excluding all historical candidates.

---

## 3. Comprehensive Glitch Catalog & Mitigations

| # | Glitch / Issue | File & Line | Severity | Mitigation Applied |
|---|---|---|---|---|
| **1** | **Accidental Trainer Attendance Punch** | `batchAttendanceController.js:1526` | 🔴 **High** | Added guard `if (!memberRows().length) return;` at entry of `punch()` and `punchAttendance()`. Only student contacts (`type === 'M'`) are collected into `cont_arr`. |
| **2** | **Teacher Payroll Loss Risk** | `controllers/attendance.php:518` | 🔴 **High** | Verified and preserved `$scope.list` in `punch()` when `memberRows().length > 0` so the conducting trainer (`type: 'E'`) is credited for class payroll in `controllers/teacherSalary.php`. |
| **3** | **Phantom Camera Scan on 0 Students** | `batchAttendanceController.js:4035` | 🟡 **Medium** | Guarded `startFaceScan()` and `openAutomaticFace()` to check `memberRows().length > 0` before initiating camera hardware or facial matching. |
| **4** | **Empty Roster Header Rendering** | `batchAttendanceController.js:1420` | 🟡 **Medium** | Roster state driven strictly by `studentCount = memberRows().length`. When `studentCount === 0`, sets `rosterState = 'EMPTY'`, which automatically hides table headers and reveals the empty state banner. |
| **5** | **Historical Attendance Blocked by Status 2** | `controllers/member.php:700` | 🔴 **High** | Updated `availableContacts` query to `m.status IN ('0','1','2')`. Date validity and session counts remain strictly enforced by `is_membership_active()`. |
| **6** | **Orphaned Past-Month Editing Lock** | `batchAttendanceController.js:1089`, `pf/attendance.php:1375` | 🟡 **Medium** | Refined `dateChange()` to compare `YYYY-MM` and reset `action_disable`. Bound `action_disable` to button `ng-disabled` and added lock warning notice. |
| **7** | **Select All / Checkbox State Distortion** | `batchAttendanceController.js:1694` | 🟢 **Low** | Filtered `selectDeselectItem()` loop strictly by `row.type === 'M'` so trainer rows do not distort `$scope.allCheck`. |

---

## 4. Technical Implementation Details

### A. Backend (`controllers/member.php`)
```php
// Lines 698-715: Allow historical active packages even if member subsequently expired
$sett = $conn->query("SELECT `renewal` FROM `membership_settings` WHERE `bid` = '".get_bid()."'")->fetch_assoc();
$availableContacts = array();
$availableResult = $conn->query("SELECT DISTINCT `m`.`contact_id` FROM `member` `m` INNER JOIN `contact` `c` ON `m`.`contact_id` = `c`.`id` WHERE `m`.`status` IN ('0','1','2') AND `m`.`contact_id` IN ({$contactIdSql}) AND `m`.`park` = '0' AND `c`.`park` = '0'");
if ($availableResult) {
    while ($available = $availableResult->fetch_assoc()) $availableContacts[(int)$available['contact_id']] = true;
}
$faceMappedContacts = array();
if (FaceAttendanceConfig::getFlag('FACE_ENROLLMENT_ENABLED', $conn, (int)get_bid())) {
    $faceTable = $conn->query("SHOW TABLES LIKE 'face_template'");
    if ($faceTable && $faceTable->num_rows > 0) {
        $mappedResult = $conn->query("SELECT DISTINCT `m`.`contact_id` FROM `member` `m` INNER JOIN `face_template` `ft` ON `ft`.`member_id` = `m`.`id` WHERE `m`.`bid` = '".(int)get_bid()."' AND `m`.`contact_id` IN ({$contactIdSql}) AND `m`.`status` IN ('0','1','2') AND `m`.`park` = '0' AND `ft`.`status` = 'ACTIVE'");
        if ($mappedResult) {
            while ($mapped = $mappedResult->fetch_assoc()) {
                $faceMappedContacts[(int)$mapped['contact_id']] = true;
            }
        }
    }
}
```

### B. Controller (`pf/js/controllers/batchAttendanceController.js`)
```javascript
// Expose memberRows on $scope
$scope.memberRows = memberRows;

// Guard automatic face capture
$scope.openAutomaticFace = async function(mode, row){
    if(mode === 'attendance' && !memberRows().length){
        show_snack('No members found for this class.');
        return;
    }
    // ...
};

// Calculate roster state based strictly on student count
var studentCount = memberRows().length;
$scope.postLoadDivShow = studentCount > 0;
$scope.noResults = studentCount === 0;
setRosterState(studentCount > 0 ? 'LOADED' : 'EMPTY');
scanRosterSnapshot = null;
if (studentCount > 0) {
    refreshAutomaticStatus();
}

// Guard punchAttendance
function punchAttendance(valid){
    if (!memberRows().length) {
        show_snack('No members found for this class.');
        return false;
    }
    // ...
    // Collect only student contacts for duplicate warnings
    var cont_arr = [];
    for (var i = 0; i < $scope.list.length; i++) {
        var cont = $scope.list[i];
        if (cont.type === 'M' && !cont.inactive && cont.selected) {
            cont_arr.push(cont.contact_id);
        }
    }
    // ...
}

// Guard punch execution
function punch(){
    if (!memberRows().length) {
        show_snack('No members found for this class.');
        return;
    }
    // ... submits $scope.list so trainer session attendance is credited for payroll
}

// Guard startFaceScan
function startFaceScan(eventType, label){
    if (!memberRows().length) {
        $scope.faceMessage = 'No members found for this class.';
        show_snack('No members found for this class.');
        return;
    }
    // ...
}
```

### C. View Template (`pf/attendance.php`)
```html
<div class="attendance-primary-actions" ng-if="attendanceStage == 'READY'" ng-cloak>
    <div class="attendance-action-buttons">
        <button type="button" class="btn btn-primary" 
            ng-if="(faceUiEnabled || browserFaceUiEnabled) && !faceSetupMode && faceEnrollmentState == 'IDLE' && browserFaceOperatingMode != 'DISABLED' && browserFaceOperatingMode != 'CAPTURE_ONLY'" 
            ng-disabled="disablePunchBtn || rosterRefreshing || !memberRows().length" 
            ng-click="openAutomaticFace('attendance')">
            <i class="fa fa-camera" aria-hidden="true"></i> Scan Arrivals
        </button>
        <button type="button" class="btn btn-success" 
            ng-if="facePunchOutUiEnabled && faceSelectionUiEnabled && !faceSetupMode && faceEnrollmentState == 'IDLE'" 
            ng-disabled="disablePunchBtn || !memberRows().length" 
            ng-click="startFacePunchOut()">
            <i class="fa fa-sign-out" aria-hidden="true"></i> Scan Departures
        </button>
        <button type="button" class="btn" 
            ng-class="browserFaceOperatingMode == 'TEST' || faceStatusPending ? (faceTestPending.length || faceTestPunchRequest ? 'btn-success' : 'btn-default') : 'btn-success'" 
            ng-if="!browserFacePilot && browserFaceOperatingMode != 'LIVE'" 
            ng-disabled="disablePunchBtn || action_disable || rosterRefreshing || !memberRows().length || faceStatusPending || (browserFaceOperatingMode == 'TEST' && (faceTestPunchBusy || ((!faceTestPending || !faceTestPunchRequest) && !faceTestPending.length)))" 
            aria-describedby="punchAttendanceHint" 
            ng-click="punchAttendance()">
            <i class="fa" ng-class="faceTestPunchBusy ? 'fa-spinner fa-spin' : 'fa-check'" aria-hidden="true"></i> {{faceTestPunchBusy ? 'Saving…' : 'Punch Attendance'}}
        </button>
    </div>
    <div class="attendance-action-meta">
        <span class="attendance-mode-pill" ng-if="browserFaceOperatingMode == 'TEST'">Test mode</span>
        <span class="attendance-action-hint text-warning" ng-if="action_disable">
            <i class="fa fa-lock"></i> Past month attendance editing requires supervisor or admin privileges.
        </span>
        <!-- ... -->
    </div>
</div>
```

---

## 5. Verification & Test Suite

### A. Backend Execution Test
```bash
php /root/.gemini/antigravity-cli/brain/22d7e8b1-fc6f-44bf-9cc3-4bd0b571849d/scratch/test_attendance_pre.php
```
```
=== RUNNING BEFOREHAND TEST CASES ===
[TC-BE-1] Historical Date (2025-02-06) Batch 10:
  Error: 0
  Student Count: 4
   - Aastha Mehta  (contact_id: 614, inactive: false)
   - Dhruv More   (contact_id: 723, inactive: false)
   - Pranshi   (contact_id: 722, inactive: false)
   - Saifuddin   (contact_id: 688, inactive: false)

[TC-BE-2] Truly Empty Date (2026-10-16) Batch 10:
  Error: 1
  Student Count: 0

=== END BEFOREHAND TESTS ===
```

### B. Frontend Logic Test
```bash
node /root/.gemini/antigravity-cli/brain/22d7e8b1-fc6f-44bf-9cc3-4bd0b571849d/scratch/test_frontend_logic.js
```
```
=== RUNNING FRONTEND LOGIC BEFOREHAND TESTS ===

[NEW FIXED LOGIC with 0 students, 1 trainer]:
  studentCount: 0
  postLoadDivShow: false (Expected: false)
  rosterState: EMPTY (Expected: EMPTY)
  attendanceStage: EMPTY (Expected: EMPTY)

[NEW FIXED LOGIC with 4 students, 1 trainer]:
  studentCount: 4 (Expected: 4)
  postLoadDivShow: true (Expected: true)
  rosterState: LOADED (Expected: LOADED)
  attendanceStage: READY (Expected: READY)

[NEW PUNCH CONTACTS]: [ 614, 723, 722, 688 ] (Only students!)

=== END FRONTEND TESTS ===
```

### C. Syntax & Linting Checks
```bash
php -l controllers/member.php && php -l pf/attendance.php && node -c pf/js/controllers/batchAttendanceController.js
```
```
No syntax errors detected in controllers/member.php
No syntax errors detected in pf/attendance.php
```

---

## 6. Operator & Support Runbook

### Scenario A: Selecting an Empty Class
- When a class has no students enrolled on the selected date:
  1. The page displays the clean empty state banner: `No members found for this class.`
  2. The table header is hidden.
  3. The action buttons (**Scan Arrivals**, **Scan Departures**, **Punch Attendance**) are completely hidden.

### Scenario B: Viewing a Historical Date (e.g. February 2025)
- Every student whose package was valid on that past date is displayed with their correct remaining sessions.
- **Admin / Supervisor Login** (`admin = 1`, `position_id = 6` or `10`): Can view and backfill attendance normally.
- **Teacher / Staff Login**: Can view the attendance records, but the **Punch Attendance** button is disabled with the lock notice:
  *`Past month attendance editing requires supervisor or admin privileges.`*
