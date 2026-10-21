# Safe Reference Retake with Recoverable Re-enrollment Review
## Pre-Implementation Specification, Benchmarks, and Verification Contract

- **Document Version**: 1.0.0
- **Status**: IMPLEMENTED / REPRESENTATIVE CAPTURE BENCHMARKS PENDING
- **Target System**: CCC Admin Panel Face Attendance System
- **Authors**: DeepMind Agentic Pair & System Architecture Team
- **Prerequisites**: Gate 1 & 2 complete; `FACE_TEACHER_SELECTION_ENABLED="0"`, `FACE_ATTENDANCE_LIVE_WRITE="0"`.

---

## 1. Executive Summary & Architectural Verdict

### 1.1 Architectural Verdict
The revised specification **"Safe Reference Retake with Recoverable Re-enrollment Review"** is **GREENLIT**.

It eliminates the three fatal flaws of previous designs:
1. **Premature Revocation**: Prevents `Set up again` from prematurely revoking active templates via `withdrawFace`.
2. **Session / Provenance Confusion**: Strictly segregates PHP login session (`session_id`), historical attempt IDs (`enrollment_session_id`), and transient capture tokens (`capture_session_token`).
3. **Data Loss During Drift Review**: Retains burst candidates as `PENDING_APPROVAL` templates in SQL and freezes attempts for audited position-1 administrator review instead of throwing and rolling back.

### 1.2 Non-Negotiable Invariants
Any implementing agent or developer MUST adhere to these non-negotiable boundaries:
- **No Application-Persisted Raw Frames**: Raw images or unencrypted arrays must never be persisted to the database, application files, or persistent storage. Transient multipart upload files in `/tmp/php*` created by PHP engine request parsing are automatically unlinked upon request termination.
- **Strict Lock Order**: Always acquire per-member lock first, tenant/model lock second, and then begin the SQL transaction. Never hold MySQL locks during Python HTTP requests.
- **No Token Leakage**: The plaintext `capture_session_token` is never written to disk or logs; only `token_hash = SHA256(token)` is stored in the database.
- **No Embedding in Client Payloads**: Decrypted 128-dimensional floating point vectors must never be returned in JSON API responses (including `getReference` and `beginEnrollment`).
- **Gated Production Deployment**: `FACE_TEACHER_SELECTION_ENABLED` and `FACE_ATTENDANCE_LIVE_WRITE` must remain `"0"` until held-out dataset calibration sign-off.
- **Strict Active Reference Filtering**: `getActiveReference()` must strictly query `WHERE fir.status = 'ACTIVE'`. Pending references belong only to capture sessions via `getSessionPendingReference(sessionId)`.
- Enrollment preview compares against the current capture session's pending reference. Before one exists it reports `NO_REFERENCE`, even during re-enrollment, so old active reference drift cannot block a new capture.

---

## 2. Baseline Benchmarks & Operating Budgets

To prevent implementation bloat or latency degradation, the implementation must meet the following performance and resource budgets:

The JSON size budgets are covered by the retake suite. The latency and peak PHP memory figures remain targets: representative camera JPEG captures and an approved inference readiness state are required before reporting p50/p95 measurements.

| Operation | Baseline Target (p50) | Upper Bound (p95) | Max Payload / Allocation |
|---|---|---|---|
| `beginEnrollment` (create/resume) | 12 ms | 35 ms | < 4 KB JSON without portrait; < 65 KB with portrait |
| `clearReference` (erase + audit) | 18 ms | 45 ms | < 2 KB JSON |
| `reviewReenrollmentAttempt` | 25 ms | 60 ms | < 2 KB JSON |
| `previewEnrollment` round-trip | 65 ms | 140 ms | Single JPEG frame <= 1 MB |
| `onboardReference` round-trip | 180 ms | 350 ms | 3 JPEG frames <= 1 MB each |
| `enrollFace` (5+3 burst commit) | 320 ms | 650 ms | 8 JPEG frames <= 1 MB each |
| `cron.face_session_cleanup.php` | 40 ms / tenant | 120 ms / tenant | Max 500 expired records / run |
| Memory Overhead (PHP-FPM) | +0 MB persistent | <= 16 MB peak / req | Frames cleared from memory after call |

---

## 3. Database Schema & Migration Dictionary (`migrate_face_retake_v5.php`)

### 3.1 DDL Specifications

```sql
-- 1. Create capture session table
CREATE TABLE IF NOT EXISTS `face_enrollment_capture_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `token_hash` CHAR(64) NOT NULL,
  `member_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `branch_id` INT NOT NULL,
  `actor_employee_id` INT NOT NULL,
  `browser_session_hash` CHAR(64) NOT NULL,
  `reference_id` INT NULL,
  `mode` ENUM('ENROLLMENT','REENROLLMENT') NOT NULL,
  `status` ENUM('ACTIVE','PENDING_ADMIN_REVIEW','CLEARED','COMPLETED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `cleared_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  UNIQUE KEY `uq_capture_token_hash` (`token_hash`),
  KEY `idx_capture_member_status` (`member_id`, `status`),
  KEY `idx_capture_expires_status` (`expires_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Alter face_identity_reference
ALTER TABLE `face_identity_reference`
  ADD COLUMN `capture_session_id` BIGINT UNSIGNED NULL AFTER `enrollment_session_id`,
  ADD COLUMN `cleared_at` DATETIME NULL AFTER `revalidated_at`,
  ADD COLUMN `superseded_at` DATETIME NULL AFTER `cleared_at`;

-- 3. Alter face_template
ALTER TABLE `face_template`
  ADD COLUMN `capture_session_id` BIGINT UNSIGNED NULL AFTER `enrollment_session_id`,
  ADD COLUMN `superseded_at` DATETIME NULL AFTER `revalidated_at`;

-- 4. Alter face_enrollment_attempt
ALTER TABLE `face_enrollment_attempt`
  ADD COLUMN `capture_session_id` BIGINT UNSIGNED NULL AFTER `reference_id`,
  ADD COLUMN `identity_drift_score` DECIMAL(5,4) NULL AFTER `reason_code`,
  ADD COLUMN `model_version_id` INT NULL AFTER `identity_drift_score`,
  ADD COLUMN `recognition_generation` CHAR(64) NULL AFTER `model_version_id`,
  ADD COLUMN `admin_override_actor_id` INT NULL AFTER `actor_employee_id`,
  ADD COLUMN `admin_override_reason` VARCHAR(255) NULL AFTER `admin_override_actor_id`,
  ADD COLUMN `admin_override_at` DATETIME NULL AFTER `admin_override_reason`;
```

### 3.2 Migration Safety Pre-Conditions
`scripts/migrate_face_retake_v5.php` must strictly enforce:
1. Defaults to dry-run (plan mode) unless `--apply` is explicitly passed.
2. Requires `--database=<name>` and `--backup=<file.sql>` (validating file existence and minimum 1 KB size).
3. Verifies `nginx-face-params.conf` contains `FACE_TEACHER_SELECTION_ENABLED "0"` and `FACE_ATTENDANCE_LIVE_WRITE "0"`.
4. Idempotent column check via `INFORMATION_SCHEMA.COLUMNS` before applying `ADD COLUMN`.
5. Data quarantine: identifies active uncalibrated or unlinked legacy references, reports counts in dry-run, and transitions unlinked incomplete records to `CLEARED` on apply.

---

## 4. API Endpoints & State Machine

```
              ┌────────────────────────────────────────────────────────┐
              │                                                        ▼
[IDLE] ──► [beginEnrollment] ──► [onboardReference] ──► [enrollFace (5+3)] ──► [ACTIVE COMMIT]
               │                      ▲        │                 │
               │                      │        │                 ▼
               │              [clearReference] └──► [REJECTED_DUPLICATE]
               │                      ▲                          │
               │                      └──────────────────────────┘
               │
               ▼ (if drift < 0.90 on Re-enrollment)
       [PENDING_ADMIN_REVIEW]
         ├──► [reviewReenrollmentAttempt (APPROVED)] ──► [ACTIVE COMMIT]
         └──► [reviewReenrollmentAttempt (REJECTED)] ──► [CLEARED & RESTORED]
```

### 4.1 `beginEnrollment`
- **Route**: `POST /routes/faceEnrollmentRoute.php?action=beginEnrollment`
- **Request**: `{ member_id: int, branch_id: int, mode: "ENROLLMENT"|"REENROLLMENT", capture_session_token?: string }`
- **Behavior**:
  - Validates login session and actor attendance module access.
  - If `mode == "ENROLLMENT"` and active templates exist: reject with `ALREADY_ENROLLED`.
  - If `mode == "REENROLLMENT"` and no active templates exist: reject with `NOT_ENROLLED`.
  - If valid unexpired `capture_session_token` provided matching `browser_session_hash` and `member_id`: resume session.
- Otherwise, atomically cancel prior incomplete sessions for this member and issue a fresh 64-char token.
- Replacement also erases prior sessions' pending portrait and embedding and any pending approval template embeddings in the same transaction.
- On re-enrollment, compare the new reference embedding with the existing active reference and return an early drift warning below 0.90; keep the active enrollment usable.
- **Response**: `{ state: "READY", capture_session_token: "...", mode: "...", expires_at: "...", has_pending_reference: bool, pending_reference: DTO|null, has_active_enrollment: bool }` (strictly no embeddings).

### 4.2 `clearReference`
- **Route**: `POST /routes/faceEnrollmentRoute.php?action=clearReference`
- **Request**: `{ member_id: int, reference_id: int, capture_session_token: string }`
- **Behavior**:
  - Acquires per-member lock `face_enrollment_{memberId}`.
  - Locks session row `FOR UPDATE`.
  - Verifies reference belongs to session and status is `PENDING_ENROLLMENT` or `REJECTED_DUPLICATE`.
  - Rejects if reference status is `ACTIVE` with `PROTECTED_ACTIVE_REFERENCE`.
  - Rejects if any templates in `face_template` have `reference_id = ? AND status = 'ACTIVE'`.
  - Erases encrypted blobs: `encrypted_portrait = ''`, `encrypted_embedding = ''`, sets `status = 'CLEARED'`, `cleared_at = NOW()`.
  - Deletes/revokes any candidate templates in `face_template` with `status = 'PENDING_APPROVAL'` for this session.
  - Invalidates session: sets `status = 'CLEARED'`, `reference_id = NULL`, `cleared_at = NOW()`.
  - Audits event via `FaceErrorLogger::record('FACE_REFERENCE_CLEARED', ...)`.
  - Idempotent: repeated calls on already cleared session return `CLEARED` success.

### 4.3 `reviewReenrollmentAttempt`
- **Route**: `POST /routes/faceEnrollmentRoute.php?action=reviewReenrollmentAttempt`
- **Request**: `{ attempt_id: int, decision: "APPROVED"|"REJECTED", in_person_verified: bool, reason: string }`
- **Behavior**:
  - Validates authenticated actor has `position_id == 1` in central `user` table.
  - Requires `in_person_verified === true` and `trim(reason) !== ''`.
  - Acquires per-member lock, then tenant lock, starts transaction.
  - Locks attempt and capture session `FOR UPDATE`.
- Verifies attempt status is `PENDING_ADMIN_REVIEW` and not expired.
- Rechecks current program authorization and recognition generation before approval. A changed generation or withdrawn consent rejects approval and erases candidates.
  - On `APPROVED`:
    - Supersedes old active templates: `status = 'SUPERSEDED'`, `superseded_at = NOW()`.
    - Supersedes old active reference: `status = 'SUPERSEDED'`, `superseded_at = NOW()`.
    - Promotes new templates to `ACTIVE`.
    - Promotes new reference to `ACTIVE`.
    - Updates attempt: `decision = 'APPROVED'`, `admin_override_actor_id = $uid`, `admin_override_reason = $reason`, `admin_override_at = NOW()`.
    - Marks session `COMPLETED`.
  - On `REJECTED`:
    - Revokes candidate templates (`status = 'REVOKED'`, zeroes embedding).
    - Clears candidate reference (`status = 'CLEARED'`, zeroes portrait and embedding).
    - Marks session `CLEARED`.
    - Retains previous active enrollment completely intact.

### 4.4 `withdrawFace` Modification
- In `FaceEnrollmentService::withdraw()`:
  - Inside the existing transaction, explicitly execute:
    ```sql
    UPDATE face_enrollment_capture_session
    SET status = 'CANCELLED', cleared_at = CURRENT_TIMESTAMP
    WHERE member_id = '{$memberId}' AND status IN ('ACTIVE', 'PENDING_ADMIN_REVIEW');
    ```
  - Ensures no open capture session can commit after consent revocation.

### 4.5 Expiry
Hourly cleanup expires both `ACTIVE` capture sessions and `PENDING_ADMIN_REVIEW` sessions after 24 hours. It erases pending portrait, reference embedding, and candidate template embeddings, then marks pending attempts expired. Register the cleaner in system cron at minute 17 hourly.

---

## 5. Test Verification Matrix & Acceptance Criteria

Before any pull request or deployment is approved, the following test matrix must pass:

### 5.1 Automated Unit & Integration Tests

| Test ID | Test Scenario | Expected Outcome |
|---|---|---|
| `TEST-RET-01` | First-time enrollment: capture reference -> clear -> fresh reference -> 5+3 bursts | First reference cleared & erased; second reference promoted to ACTIVE with 3 templates. |
| `TEST-RET-02` | Duplicate blocked: duplicate face captured -> REJECTED_DUPLICATE -> Clear & Retake -> correct face | Old blobs wiped; correct child enrolled successfully. Unchanged conflicting child templates. |
| `TEST-RET-03` | Re-enrollment normal: similarity >= 0.90 | Atomic transition: old templates & reference become SUPERSEDED at commit; new become ACTIVE. |
| `TEST-RET-04` | Re-enrollment drift: similarity < 0.90 | Attempt enters PENDING_ADMIN_REVIEW; candidate templates enter PENDING_APPROVAL; old active enrollment untouched. |
| `TEST-RET-05` | Admin drift approval | Position-1 actor approves with reason; old records superseded, new records promoted. |
| `TEST-RET-06` | Admin drift rejection | Position-1 actor rejects; candidate blobs erased; old active records remain ACTIVE. |
| `TEST-RET-07` | Non-admin override attempt | Actor with `position_id != 1` attempts approval; request rejected with `UNAUTHORIZED_ADMIN_OVERRIDE`. |
| `TEST-RET-08` | Clear protected ACTIVE reference | Call `clearReference` on an `ACTIVE` reference; rejected with `PROTECTED_ACTIVE_REFERENCE`. |
| `TEST-RET-09` | Clear idempotency | Calling `clearReference` twice with same token returns `CLEARED` without side-effects or errors. |
| `TEST-RET-10` | TOCTOU race: clear during reference inference | `clearReference` arrives while Python infers; late reference commit fails with `STALE_CAPTURE_SESSION`. |
| `TEST-RET-11` | TOCTOU race: clear during 5+3 burst inference | `clearReference` arrives while Python infers bursts; late commit fails with `STALE_CAPTURE_SESSION`. |
| `TEST-RET-12` | Consent withdrawal during capture | `withdrawFace` called while capture session is open; session cancelled; subsequent commit fails. |
| `TEST-RET-13` | Multi-tab split-brain | Tab 1 starts session A; Tab 2 starts session B for same child. Session A is invalidated; Tab 1 cannot commit. |
| `TEST-RET-14` | Cron hourly cleanup | Expired incomplete session (>24h) has portrait/embedding scrubbed and status set to `EXPIRED`. |
| `TEST-RET-15` | Payload privacy assertion | Assert JSON responses of `beginEnrollment`, `getReference`, `onboardReference`, `enrollFace` contain NO `embedding` key. |

### 5.2 Browser & UI Contract Tests
- **`FACE-ENR-UI-09` Preservation**: Ensure all existing string assertions in `FACE-ENR-UI-09-cross-member-duplicate-recovery-contract.js` pass unaltered.
- **`FACE-ENR-06` correction**: Its pending-as-active assertion is obsolete and may be updated to expect a session-bound pending reference. Keep `FACE-ENR-UI-09` unchanged.

Measure latency and PHP peak memory targets with representative JPEG captures; synthetic stubs only verify behavior.
- **`FACE-ENR-UI-15-safe-retake-contract.js`**: Add new contract asserting:
  1. Clear & Capture Again button exists in pending reference badge and duplicate alert box.
  2. Badge displays `"Reference Portrait (Awaiting Verification)"` when pending.
  3. Clicking Clear resets all temporary frames, timers, and sets state to `REFERENCE_REQUIRED`.
  4. Monotonic request sequence tagging drops late responses from older sessions.

---

## 6. Implementation File Boundary Checklist

Implementing agents must restrict modifications strictly to the following files:

```
[MIGRATION]
  └── scripts/migrate_face_retake_v5.php              (NEW migration script)

[CRON]
  └── classes/cron.face_session_cleanup.php          (NEW cron cleaner)

[ROUTES]
  └── routes/faceEnrollmentRoute.php                 (Wire beginEnrollment, clearReference, reviewReenrollmentAttempt)

[CONTROLLERS & SERVICES]
  ├── controllers/faceEnrollment.php                 (Add action handlers, validate session & position_id == 1)
  └── classes/services/FaceEnrollmentService.php     (Two-tier locks, session checks, drift handling, DDL updates)

[FRONTEND]
  ├── pf/attendance.php                              (Update reference badge copy and add Clear & Retake buttons)
  └── pf/js/controllers/batchAttendanceController.js (Session token propagation, sequence IDs, timer cleanup)

[TESTS]
  ├── tests/attendance/FACE-ENR-07-safe-retake-assurance.php (NEW comprehensive PHP assurance suite)
  └── tests/attendance/FACE-ENR-UI-15-safe-retake-contract.js (NEW browser UI contract test)
```

No modifications are permitted to:
- `classes/dbconnection.class.php` (never touch credentials or connection handling)
- `services/face-inference/` (Python inference microservice remains untouched)
- `nginx-face-params.conf` (keep selection and live write disabled at 0)
- `tests/attendance/FACE-ENR-UI-09-cross-member-duplicate-recovery-contract.js` (must pass as-is)

## Rollback and release check

The schema change is additive. If the new workflow must be stopped, disable face enrollment in application configuration, remove the minute-17 cleanup cron entry, and revert the PHP/UI code together. Keep teacher selection and attendance live write set to `0`. Leave the additive columns and session table in place until all in-flight sessions have expired. The verified pre-migration SQL dumps are `/tmp/ccc_database_face_retake_v5_20260930.sql` (SHA-256 `0db9f162aabb7ad086e66f43f86b5ace3c0846a87759200a7fbabd0ee3cb0d83`) and `/tmp/ccc_database_test_face_retake_v5_20260930.sql` (SHA-256 `14dc46584ae82aa276e66f423dadaadd4d3baff53afc182733416dc94241ddd3`). Restore a whole dump only if post-backup writes can be discarded; otherwise preserve the additive schema and roll back code only.
