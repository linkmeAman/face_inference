# Historical camera attendance correction plan: P0–P2

Updated 3 October 2026. This plan describes the earlier P0–P2 work and is kept
for its test rationale and original execution contracts. Those phases have
since been implemented and verified on `master`; the final status and guided
enrollment behavior are documented in `handoff-context.md`. This file no longer
defines the active execution scope.

## Superseded by completed work

- P0, P1, and P2 were completed before the guided-enrollment B1/B2 work.
- Guided enrollment and six-template recognition were added in commits
  `fff3ef341` through `c5c14aaf9`. The six-template recognition integration was
  separately approved after review exposed the old three-template scan cap.
- Final verification: 51 phase behavior cases passed, 118 Python tests passed,
  and enrollment/retake assurance passed 27 + 15 scenarios.
- No restart, reload, deployment, threshold change, or schema change was part
  of the guided-enrollment implementation. Real-person pose accuracy remains
  unverified.

The detailed P0/P1/P2 text below is retained as historical design context. Its
old approval and status statements do not describe the current repository state.

## Purpose and boundaries

Keep the existing attendance page and teacher workflow. Recognition selects a
Present row; the existing **Punch Attendance** action commits attendance and
membership deductions. Enrollment saves a template after its verification passes.
Those are different operations; “automatic saving” does not authorize automatic
attendance commitment.

P0 adds reproducible evidence. P1 addresses capture ownership, departure gating,
and enrollment frame integrity. P2 addresses classified service failures and
recovery of an uncertain recognition response without duplicate inference or
loss of accepted selections. Neither phase can promise zero wrong matches.

No application, deployment, configuration, calibration, inference-model, schema,
threshold, rate-limit, Nginx or cleanup work belongs to P0. No production table
is modified. Master must retain its starting commit. Work is on
`test/camera-p0-baseline-20261002` from
`18944898cbc4b3e957cec1023e13a9da391ffe63`.

## P0: tests and documentation only

The exact repository allowlist is the following ten files. Every other file is
excluded, including all existing tests and all production code.

| File | Functions and purpose |
|---|---|
| `tests/attendance/helpers/face-controller-harness.js` | `createHarness`, `advanceClock`, `resolveRequest`, `resolveCamera`, `dispose`: load the entire production controller in Node VM with controlled browser boundaries. Shared setup and case reporting distinguish fixture errors from assertion failures. |
| `tests/attendance/FACE-CAM-03-capture-lifecycle-behavior.js` | Four behavior cases drive actual start, switch, cancel and hidden-page actions. Check delayed stream disposal and which overlapping switch owns the video. |
| `tests/attendance/FACE-UI-15-departure-behavior.js` | Three cases drive actual detection polling and recognition requests. Continuous presence, short absence and unavailable detection must not authorize another attempt. |
| `tests/attendance/FACE-ENR-UI-18-capture-integrity-behavior.js` | `reachSeparation` plus three cases exercise both bursts and student cancellation. Compare validated/submitted bytes and geometry; distinguish unavailable detection from observed departure. |
| `tests/attendance/FACE-UI-16-attempt-recovery-behavior.js` | `nextAttempt` plus six cases test accepted-row preservation, lost responses, bounded recovery and cancellation. Drive public controller actions and actual request callbacks. |
| `tests/attendance/helpers/face-api-recovery-fixture.php` | `recoveryDb`, `recoverySchema`, `recoveryReset`, `RecoveryFixture` and provider fakes supply connection-scoped temporary tables and synthetic inference. `recoveryCase` reports assertion failures separately from setup/runtime errors. |
| `tests/attendance/FACE-API-12-attempt-recovery.php` | Nine cases call the real API service. Test completed-attempt retries, payload conflicts, attempt status, frame counts, optional readiness, access checks and absence of attendance/deduction writes. |
| `tests/attendance/FACE-INFRA-04-readiness-classification.php` | Four cases call the concrete PHP client through an ephemeral loopback HTTP fixture. Compare boolean readiness guards with the proposed optional classification capability; no live inference request occurs. |
| `tests/attendance/run-camera-phase-tests.py` | `run` and `main` execute fixed manifests and validate complete structured results. Only named assertion failures with the exact recorded first message line can be expected failures. |
| `docs/ai/face-attendance-implementation-plan.md` | Record current scope, evidence, commands, limitations and later phase contracts. Remove obsolete active execution instructions from this document. |

### Isolation and blast radius

The new API fixture connects only to the exact database `ccc_database_test`.
Its temporary tables are `face_selection_control`, `batch`, `batch_time`,
`member`, `contact`, `invoice_invoiceitem_module_view`, `biometric_consent`,
`face_template`, `face_model_version`, `attendance_scan`,
`attendance_scan_roster`, `recognition_attempt`, `attendance`, `invoice_item`
and `attendance_event`. They shadow persistent names only on that connection
and disappear when it closes. These are fixture tables, not a migration.
The existing lifecycle test additionally checks access to `ccc_central_test`.
The runner pins both database names; it never forwards a deployment database name.

No HTTP call reaches the website or the inference service. Synthetic provider
results exercise PHP bookkeeping, not face identity. The readiness fixture
binds an OS-assigned loopback port and terminates its child process afterward.
The Node harness captures actual controller requests but never sends them.

A P0 test/helper failure blocks the test gate; it does not alter attendance or
deductions. Incorrect documentation could mislead a later implementation, so
later approval must include the scoped diff and test evidence. The runtime files
and manual attendance writer remain byte-identical to the before tag.

### Tests written first and observed baseline

All new regression cases were written before any application change. “Expected
failure” means a reproduced unmet contract, not a passing feature. A case stops
at its first failing assertion; later assertions in that case are not claimed as
verified. The exact manifest and failure messages live in the runner.

| Suite / case | Assertion and why current code fails | P0 result |
|---|---|---|
| CAM-03 `late_camera_after_cancel` | A switched stream arriving after cancel must be stopped; switch success callbacks lack a lifecycle guard. | Expected failure |
| CAM-03 `latest_switch_owns_stream` | A late older switch must not replace the newer stream; no switch generation owns the callback. | Expected failure |
| CAM-03 `initial_camera_cancel_guard`, `hidden_page_stops_tracks` | Initial delayed camera and visibility cleanup stop tracks. These existing guards already work. | 2 pass |
| UI-15 `same_person_never_rearms_by_timeout` | Continuous presence cannot rearm; the 1,600 ms timeout currently bypasses departure. | Expected failure |
| UI-15 `departure_requires_750ms` | One detector-empty interval shorter than 750 ms cannot rearm; one empty observation currently suffices. | Expected failure |
| UI-15 `preview_failure_is_not_absence` | Detector failure cannot prove departure; the current availability guard works. | Pass |
| ENR-UI-18 `submitted_frame_equals_checked_frame` | Submitted JPEG bytes and capture context must be exactly those checked; current code captures again after validation. | Expected failure at JPEG comparison |
| ENR-UI-18 `unavailable_is_not_burst_separation` | An outage cannot qualify as observed departure between bursts; current false callback conflates both. | Expected failure |
| ENR-UI-18 `old_student_callback_is_ignored` | A delayed previous-student validation cannot append frames; existing request sequence guard works. | Pass |
| UI-16 `temporary_failure_preserves_selection_INFERENCE_BUSY`, `_INFERENCE_OFFLINE`, `_INFERENCE_TIMEOUT` | Temporary failures retain already accepted rows; current stop routine discards them. | 3 expected failures |
| UI-16 `lost_response_checks_status_first` | Query that attempt before recapture or cancellation; current code cancels immediately. | Expected failure |
| UI-16 `bounded_retry_and_cancel` | Recovery checks use 1/2/4-second delays, then stop retrying; cancellation clears pending work. Current code has no recovery sequence. | Expected failure at backoff assertion |
| UI-16 `cancel_ignores_late_attempt` | A result arriving after cancel cannot select another row; existing generation guard works. | Pass |
| API-12 `same_completed_attempt_keeps_nonce` | Retry the same completed payload with its original nonce without new inference; current nonce consumption precedes duplicate lookup. | Expected failure |
| API-12 `changed_payload_conflicts` | Same ID with different bytes must return `IDEMPOTENCY_CONFLICT`; current duplicate lookup has no payload fingerprint. | Expected failure |
| API-12 `status_finds_attempt` | Status must identify the requested completed attempt; current status returns aggregate scan state only. | Expected failure |
| API-12 `exactly_three_frames` | Exactly three frames must be required independently of the nonce flag; current general contract accepts one to five. | Expected failure |
| API-12 `temporary_readiness_failure_keeps_scan` | A provider explicitly classifying temporary outage must keep the scan active; current code only uses boolean readiness and cancels it. | Expected failure |
| API-12 legacy ready/unready providers, access/nonce/closed-scan guards, no attendance/deduction writes | Old interface implementations still work; unauthorized/replayed/closed attempts are rejected; recognition only selects a row. | 4 pass |
| INFRA-04 `legacy_boolean_readiness_transport`, `offline_transport_is_not_ready` | Concrete cURL returns true only for ready HTTP evidence and false for unready, busy, malformed, timeout and refused connection. | 2 pass |
| INFRA-04 `readiness_failure_classification`, `offline_readiness_classification` | Concrete client should expose optional classified readiness; that capability does not exist yet. | 2 expected failures at capability assertion |

Full P0 gate: **11 new behavior cases pass; 18 named expected failures**.
Unchanged guard suites: **9 JavaScript contracts pass**, **19 API lifecycle
scenarios pass**, **all 51 FACE-PILOT-01 assertions pass**, and the existing
OpenAPI contract passes. These counts are enforced by a fixed case manifest and
explicit legacy lifecycle/smoke count checks. Setup errors, missing cases,
process crashes, skipped tests and changed assertion reasons fail the gate.

The initial sandbox runs could not open DB/local-socket connections and failed
the gate as BLOCKED. Authorized runs outside that restriction passed the P0
baseline. This is not evidence of production readiness.

## P1: capture lifecycle and enrollment integrity (not executed)

The runtime implementation belongs in
`pf/js/controllers/batchAttendanceController.js` and, only where required by the
agreed state transitions, `pf/js/controllers/faceAttendanceController.js`.
The page layout and manual Punch Attendance flow stay unchanged.

- `startFaceEnrollment`, `switchFaceCamera`, stop/cancel functions and asynchronous
  callbacks: bind streams and capture work to current lifecycle and student;
  dispose late streams and let only the latest switch own the video.
- `waitForNextFace`: remove timeout-based departure; require reliable absence
  across at least 750 ms followed by one valid face. Detector failure resets or
  pauses departure evidence and never counts as an empty scene.
- `checkCurrentCaptureFrame`, `captureFaceEnrollment`, `submitFaceEnrollment`:
  retain the checked JPEG/context and submit that exact pair; represent valid
  face, observed absence and unavailable/error distinctly during burst separation.
- `FaceAttendanceStateMachine`: preserve the existing capture-in-flight guard;
  any transition additions must be independently tested, not inferred from UI text.

P1 is deployable/revertible without P2. It changes capture behavior only; P2
recovery failures remain explicit expected failures in the P1 gate. A P1 failure
can interrupt camera/enrollment activity; it must not change the manual writer,
attendance records or deductions. Update this document with the approved exact
P1 diff before execution; P0 does not authorize expanding that diff.

## P2: classified readiness and attempt recovery (not executed)

Planned runtime boundaries are the existing batch controller/state machine,
`classes/clients/FaceInferenceClient.php`,
`classes/services/FaceAttendanceApiService.php`, and
`controllers/faceAttendance.php`; shared API documentation must describe the
resulting optional recovery fields. No interface, migration or deployment change
is part of this work. The exact P2 file/function diff requires P2 approval.

- `FaceInferenceClient::readinessStatus()` is an **optional concrete capability**.
  The existing `FaceRecognitionProvider.php` interface stays untouched.
  Service capability dispatch uses `is_callable([$provider, 'readinessStatus'])`;
  fallback `checkReadiness() === true` means ready, and unclassified false remains
  terminal `SYSTEM_NOT_READY`. Never call the optional method unguarded.
- `startFaceScan`, `recognizeFaceAttempt`, `getFaceScanStatus` classify failure and
  expose recovery without guessing a match. Classified busy, offline and timeout
  can retry; invalid calibration/model readiness and unclassified failure stop.
- Completed attempt replay verifies owner/browser/scan and a payload fingerprint
  before resolving the stored result, without consuming a new nonce or inferring
  again. Changed payload with the same ID conflicts. New attempts retain nonce
  and scan locking. Fingerprint metadata uses existing recognition metadata;
  a schema change is outside scope.
- Status gains optional attempt lookup (`RECORDED`, `PROCESSING`, `NOT_FOUND`).
  Proposed service arguments after the existing three are client attempt ID and
  browser binding; controllers must forward them. Recovery fields are
  `retryable`, `retry_after_ms`, `recovery_action` (`CHECK_STATUS`, `RECAPTURE`, `STOP`).
  A lost response triggers status checks first with bounded 1/2/4-second delays.
  Accepted selections survive temporary failure; cancellation invalidates timers
  and late callbacks. Fresh capture follows explicit server recovery guidance.
- Enforce exactly three frames only when both existing positive-fixture suites
  are updated: **FACE-API-01 and FACE-PILOT-01**. The latter currently passes two
  frames in Section 3 and one frame in three Section 4 calls. Update all four
  positive calls without dropping any of its 51 assertions. Both files stay
  unchanged in P0/P1. Retain negative count tests in API-12.
- Preserve **FACE-API-07** unchanged. It is a source-string boundary check, not an
  AST parser: it slices between `startFaceScan` and `recognizeFaceAttempt`.
  Put new service helpers after `recognizeFaceAttempt` so their contents cannot
  contaminate that check. Every provider mock must still satisfy the old interface.

P2 depends on P0 tests and is reviewed after P1. It must remain separately
revertible. Failure can stop scan selection/recovery or mishandle retries, so the
smoke test and no-attendance-write guards are mandatory. The existing teacher
transaction remains the only attendance/deduction commit path.

## Commands and phase gates

P0 start tag was created before edits. Only the P0 commands are authorized now:

```bash
git tag -a camera-p0-before -m 'Before camera P0'
git switch -c test/camera-p0-baseline-20261002
python3 tests/attendance/run-camera-phase-tests.py --phase p0 --baseline
```

P0 commit stages exactly the ten allowlisted paths, then runs `git diff --cached
--check`. Its commit message is `test(camera): establish P0 regression baseline`.
After review and passing gate:

```bash
git commit -m 'test(camera): establish P0 regression baseline'
git tag -a camera-p0-after -m 'Camera P0 baseline recorded'
```

The following commands are listed for later approval; **not executed in P0**:

```bash
git tag -a camera-p1-before -m 'Before camera P1'
python3 tests/attendance/run-camera-phase-tests.py --phase p1
# Stage only the separately approved exact P1 file list.
git diff --cached --check
git commit -m 'fix(camera): bind captures to the active lifecycle'
git tag -a camera-p1-after -m 'Camera P1 verified'

git tag -a camera-p2-before -m 'Before camera P2'
python3 tests/attendance/run-camera-phase-tests.py --phase p2
# Stage only the separately approved exact P2 file list.
git diff --cached --check
git commit -m 'fix(camera): recover recognition attempts without duplicate work'
git tag -a camera-p2-after -m 'Camera P2 verified'
```

P0 pass: unchanged guard suites green; 11 new cases pass and exactly the 18
recorded defects fail. P1 pass: six P1 defects now pass; only the 12 P2 defects
may remain expected failures. P2 pass: every case and guard passes with no
expected failures. Running P0 without `--baseline` intentionally returns nonzero
until the defects are fixed. A baseline pass never authorizes deployment.

Rollback of a single phase commit is `git revert --no-edit camera-pN-after`
on that work branch (substitute 0, 1 or 2). Do not reset master or retag existing
checkpoints. P0 changes only tests/docs, so reverting it changes no runtime files
or DB state. P1/P2 rollback must be tested against their actual later commits;
no claim is made now that those future rollbacks have been proved.

P0 rollback rehearsal uses a separate sparse staging checkout at
`/tmp/camera-p0-rollback-verified-20261002`. It applies the exact staged P0 diff to
the before commit and creates a staging-only after tag. These verification
commands must both exit zero, with an empty working-tree status:

```bash
git -C /tmp/camera-p0-rollback-verified-20261002 revert --no-edit camera-p0-after
git -C /tmp/camera-p0-rollback-verified-20261002 diff --exit-code camera-p0-before HEAD
git -C /tmp/camera-p0-rollback-verified-20261002 status --porcelain
```

The rehearsal verifies the complete Git tree, including files outside the sparse
checkout. It performs no revert in the application checkout and no DB operation.
Runner evidence handling is additionally checked with synthetic subprocess
results: the precise baseline passes; strict mode, setup errors, changed
assertions, missing cases and unexpected baseline passes all return failure.

## What these tests cannot establish

- No real person, recorded-camera corpus, actual camera hardware or mobile browser
  participates. Pixel tokens distinguish captures but are not valid face images.
- No measured false accept/reject rate, sibling/lookalike separation, motion,
  lighting, glasses, real multi-face behavior, spoof resistance or human-guidance
  usability result is available from P0.
- Real camera switching/permission revocation, background-tab behavior and device
  frame timing require browser/device tests with people. VM tests cover callback
  ownership, not the browser media implementation.
- API tests are serial and synthetic. Concurrent multi-process recovery, lost
  production HTTP responses and real inference queues remain unverified.
- p95 <= 2 seconds on two cores has **not** been measured. No load is sent to
  production for P0; fake timers and the readiness transport fixture are not a
  capacity benchmark. Any later capacity result needs an isolated replica and
  representative frames before it can justify deployment.

## Stop conditions and decisions

Stop and report instead of widening scope if an implementation requires an
excluded file, persistent schema/data changes, a new model/configuration,
threshold/rate-limit edits, or a deployment operation. Also stop for an unrelated
baseline failure, missing test prerequisites, an unexpected failure reason,
missing/skipped cases, source changes by another actor, master moving, or any
production attendance/deduction side effect. Do not weaken old assertions,
convert arbitrary errors into expected failures, or update tags in place.

No extra business decision is needed for P0. P1 and P2 need separate execution
approval and review of their exact diffs. Decisions about real-device/person
availability and the isolated capacity environment remain outside P0; they are
required before claiming the camera pipeline is ready for operational use.
