# ATT-FIX-01 Characterization Tests

## Current face-attendance verification state (2026-10-05)

The current API supports shadow and live-write configurations:

- shadow mode returns an accepted selection for the separate Punch Attendance
  action; live-write mode records attendance through the attendance-domain
  transaction after an accepted, live recognition result;
- repeated live scans return the existing present state without duplicate rows;
- uncertain or rejected faces do not write attendance or infer absence;
- API v1 exposes start, attempt, status/resume, complete, cancel, enrollment,
  and revocation for web and mobile clients;
- provider ID/version, registry model name/ID, embedding dimension, and
  calibration policy are bound before templates are compared;
- PAD remains `DEFERRED`; `SPOOF` is rejected and uncertain/challenge results
  remain non-writing while scanning continues automatically;
- the disposable suites and browser contracts are described in their sections
  below; the current checkout also includes the early setup identity contract.
- The 2026-09-22 disposable run also passes the provider, OpenAPI, and additive
  test-setup contracts. Its model-integrity mismatch and missing-manifest
  lines are intentional negative checks.

The current-server readiness preflight is intentionally separate from the
disposable suite. The approved setup run created the persistent face schema in
`ccc_database_test` and used `ccc_central_test` only for guarded connectivity
validation. The required preflight now passes all 25 assertions, and the
Nginx/PHP configuration is installed; the real camera smoke test remains.

These tests exercise the existing manual attendance domain method,
`attendance::punch_attendance()`, against a disposable MySQL database. The
repository has no root PHPUnit configuration, so the runner is standalone and
PHP 7.4 compatible.

The runner defaults to `ccc_database_test` and `ccc_central_test`. It refuses
database names outside the `ccc_database_test*` and `ccc_central_test*` test
namespaces. It creates only connection-scoped temporary fixture tables in the
application test database, so persistent test data is not truncated or
overwritten. It must never be pointed at a live database.

## Run

Create `ccc_database_test` and `ccc_central_test`, then run:

```sh
ATTENDANCE_TEST_DB_HOST=127.0.0.1 \
ATTENDANCE_TEST_DB_USER=test_user \
ATTENDANCE_TEST_DB_PASSWORD='test_password' \
php tests/attendance/ATT-FIX-01-characterization.php --required
```

The required mode exits nonzero with `BLOCKED` if either test database or the
PHP MySQL extension is unavailable. Without `--required`, missing test
infrastructure is reported as `SKIPPED` and exits zero for optional local runs.
The same required mode can be enabled with `ATTENDANCE_TEST_REQUIRED=1`.

Override the names only within the approved test namespaces:

```sh
ATTENDANCE_TEST_DB=ccc_database_test \
ATTENDANCE_TEST_CENTRAL_DB=ccc_central_test \
php tests/attendance/ATT-FIX-01-characterization.php --required
```

## Covered baseline behavior

- employee automatic presence;
- limited membership deduction;
- unlimited membership with `sessions = 0`;
- absence with deduction disabled and enabled;
- inactive/expired state (`negative = 2`);
- repeated submission in one session;
- two sessions on one date, each recording its own session-aware attendance;
- previous-month submission rejected by the server-side policy.

The ATT-FIX-01 expectations now describe the corrected contract. The separate
regression runner below was created and run before the production changes; its
pre-change baseline failed all three target scenarios.

## ATT-FIX-02 through ATT-FIX-04 regression run

Run the desired-behavior tests before and after the production changes:

```sh
ATTENDANCE_TEST_DB_USER=test_user \
ATTENDANCE_TEST_DB_PASSWORD='test_password' \
php tests/attendance/ATT-FIX-02-04-regressions.php --required
```

The pre-change run is expected to fail for the missing unlimited-membership
helper, cross-session duplicate behavior, and previous-month server guard. The
post-change run must pass all three scenarios.

## ATT-CORE-01 shared attendance domain service

The ATT-CORE-01 test was created and run before the service implementation. Its
pre-change event baseline passed attendance behavior but failed event creation.
The service-contract test covers recording and deducting a present member,
atomic `attendance_event` creation, idempotent duplicates, different sessions,
database unique-key rejection, punch-out without deduction, branch
authorization, and server-side date rejection.

```sh
ATTENDANCE_TEST_DB_USER=test_user \
ATTENDANCE_TEST_DB_PASSWORD='test_password' \
php tests/attendance/ATT-CORE-01-domain-service.php --required
```

The runner uses connection-scoped temporary tables and refuses databases
outside the approved test namespaces. The current event-enabled run passes
6/6 scenarios.

## ATT-DB-01 migration run

The ATT-DB-01 test was created and run before the migration files. Its baseline
reported `duplicate_groups=5` and `session_zero_rows=21073`, then failed because
the migration files were missing.

```sh
ATTENDANCE_TEST_DB_USER=test_user \
ATTENDANCE_TEST_DB_PASSWORD='test_password' \
php tests/attendance/ATT-DB-01-migration.php --required
```

The test applies only the seven new tables when none already exists, verifies
the required unique keys, and removes only tables created by that run. It
refuses to run destructively over an existing face deployment. The existing
attendance summary key is not used for the fresh-start rollout; historical
attendance rows are left unchanged.

## FACE-ML-01 no-write inference spike

The private Python service lives under `services/face-inference/`. Its contract
tests were created before the service implementation; the pre-change run
reported the missing service module. Run the service tests with:

```sh
python3 services/face-inference/tests/test_contract.py
python3 services/face-inference/tests/test_http_contract.py
python3 -m compileall -q services/face-inference/app services/face-inference/tests
```

The service has no attendance or membership writer. Model weights, licensing,
held-out calibration, and representative-device latency measurements remain
required before enabling automatic acceptance.

## FACE-ENR-01 first mapping lifecycle

The PHP enrollment service requires an active program member, teacher
confirmation of the selected child, 7–10 frames, successful same-day
verification, and encrypts no more than three templates. It records
`PROGRAM_AUTHORIZED` metadata instead of requiring a guardian-consent flag.
Withdrawal revokes active templates immediately.

```sh
ATTENDANCE_TEST_DB_USER=test_user \
ATTENDANCE_TEST_DB_PASSWORD='test_password' \
php tests/attendance/FACE-ENR-01-enrollment.php --required
```

The current disposable lifecycle run passes 9/9 scenarios, including
cross-member duplicate detection, provider/model binding, revocation, and
authenticated-staff enrollment without a visible confirmation gate. Browser enrollment and mapping contracts are
covered separately by the `FACE-ENR-UI-*` JavaScript tests.

## FACE-API-01 PHP scan lifecycle

The PHP API supports two explicit modes. Shadow mode returns `SELECT_PRESENT`
for the existing Punch Attendance flow. When live write is enabled and shadow
mode is disabled, an accepted roster match returns `ATTENDANCE_RECORDED` after
the existing attendance-domain transaction commits a present event. Repeated
scans return `ALREADY_PRESENT`; uncertain and rejected faces do not write
attendance or infer absence. It exposes the lifecycle through the shared
v1 operation names:

- `startFaceScan`
- `recognizeFaceAttempt`
- `completeFaceScan`
- `cancelFaceScan`
- `enroll`
- `revoke`

The shared route is `routes/v1/face-attendance.php`; the legacy route remains
an internal UI aid. It validates the selected branch, batch, date, attendance
session, and scheduled time, freezes the exact eligible roster, and records
recognition metadata without writing `attendance`, absence, or membership
balances. A non-session batch may use `session_id=0`; the browser sends the
scheduled raw time rather than its display label. Unresolved members remain
available for the existing manual flow. The v1 route requires HTTPS by default,
uses an exact browser-origin allowlist, and supports the existing authenticated
device/session headers for web and mobile clients.

Run the focused test against disposable databases:

```sh
ATTENDANCE_TEST_DB_USER=test_user \
ATTENDANCE_TEST_DB_PASSWORD='test_password' \
php tests/attendance/FACE-API-01-scan-lifecycle.php --required
```

Required mode reports `BLOCKED` and exits nonzero when either disposable
database is unavailable. Do not point this test at production databases.
The verified disposable-database run currently passes all 16 scenarios,
including FACE-API-05 for the non-session batch case, deferred-PAD row
selection, idempotency conflicts, revoked members, membership rechecks, and
FACE-API-07's transient inference recovery ordering.

## FACE-API-07 transient inference recovery

Startup prepares the private inference sidecar before persisting a database
scan. A temporary `INFERENCE_UNAVAILABLE` response therefore remains
retryable and does not create a misleading `CANCELLED` scan; only the explicit
Stop Face Scan action cancels an active scan.

The contract was created and run before the service ordering correction:

```sh
node tests/attendance/FACE-API-07-transient-inference-contract.js
```

Its pre-change baseline failed the initialization-before-persistence assertion.
The post-change contract passes.

## FACE-ML-03 private inference availability

This read-only check verifies the configured private `/health` endpoint,
model readiness, and model integrity. It does not send frames, initialize a
scan, or write application data:

```sh
FACE_INFERENCE_URL=http://127.0.0.1:6120 \
php tests/attendance/FACE-ML-03-service-availability.php
```

Run it from the host runtime when the configured loopback service is being
verified. The active calibration policy is applied automatically; uncertain matches remain non-writing and scanning advances to the next child.

## FACE-UI-02 punch-in detection highlight

The punch-in camera includes a visual guide and a green box from the private
YuNet preview. The preview is requested through the authenticated
`previewFace` attendance action using one temporary bounded frame at a time.
It returns only face-box/quality metadata and never writes attendance. No-face
and multiple-face results show instructions and hide the box; final matching
remains server-side.

The test was created before the UI and route change. Run it with:

```sh
node tests/attendance/FACE-UI-02-punchin-overlay-contract.js
```

The face start request also derives `attendance_session_id` from the selected
time entry when the batch field has not yet been populated. This preserves the
valid `0` value used by non-session batches. Server error messages are passed
through to the teacher without a client-side callback exception.

## FACE-UI-05 selection persistence and scan restart

The attendance page keeps the selected date, batch, and time in tab-scoped
`sessionStorage` across refreshes, restores them after asynchronous batch
loading, and replaces or clears them when the teacher deliberately changes
the selection. Stop Face Scan remains restartable, and the unused assistant-
trainer employee request is commented out in the controller.

```sh
node tests/attendance/FACE-UI-05-selection-recovery-contract.js
```

The test was created before the change; its baseline failed six assertions and
the post-change contract passes.

## FACE-UI-06 recognized child and duplicate face handling

Accepted member IDs are tracked only for the active scan. Canonical
`RECORDED` and `ALREADY_MARKED` responses mark the roster row Present; a repeat
face displays an already-scanned message and the next child can continue.
Uncertain and shadow results remain non-writing; the active scanner advances
to the next child without asking for teacher review.

```sh
node tests/attendance/FACE-UI-06-recognized-child-flow-contract.js
```

The test was created before the change; its baseline failed four assertions and
the post-change contract passes.

## FACE-UI-07 stop during recognition

Stop Face Scan is valid while recognition is in flight. The state machine
transitions from `PROCESSING` to `CANCELLED`, invokes camera cleanup, and
ignores the late response. The scan can be started again afterward.

```sh
node tests/attendance/FACE-UI-07-stop-processing-contract.js
```

The test was created before the state transition change; its baseline failed
with `Invalid face attendance transition: PROCESSING -> CANCELLED` and the
post-change contract passes.

## FACE-UI-08 suppress repeated reviewed faces

When a review response includes an identified `member_id`, the active scan
remembers it. A repeat shows an already-scanned message and does not reopen the
same review popup repeatedly. The review result remains non-writing.

```sh
node tests/attendance/FACE-UI-08-review-repeat-contract.js
```

The test was created before the change; its baseline failed one assertion and
the post-change contract passes.

## FACE-ML-02 approval and calibration

Run the approval/calibration contract tests:

```sh
python3 services/face-inference/tests/test_approval_calibration.py
```

The calibration tool verifies model-file SHA-256 checksums and approval
metadata, calculates a strict score/margin operating point from held-out
genuine/impostor score records, and fails when P95 latency exceeds 250 ms or
false accepts are possible:

```sh
python3 services/face-inference/tools/calibrate.py \
  services/face-inference/models/approval-manifest.json \
  services/face-inference/models/held-out-scores.json \
  services/face-inference/models/calibration-approved.json
```

Do not commit real child images, embeddings, secrets, or model weights. Set
`FACE_CALIBRATION_FILE` only to an approved generated artifact.

## FACE-ENR-01 route and FACE-UI-01 state tests

```sh
php tests/attendance/FACE-ENR-01-route-contract.php
php tests/attendance/FACE-LOG-01-safe-error.php
node tests/attendance/FACE-UI-01-state-machine.js
```

The browser-side ATT-FIX-02 regression verifies that `sessions = 0` remains
eligible while exhausted finite memberships are excluded:

```sh
node tests/attendance/ATT-FIX-02-browser-unlimited.js
```

Enrollment routes are disabled unless `FACE_ENROLLMENT_ENABLED=1`. The camera
controls are disabled unless `FACE_ATTENDANCE_ENABLED=1`; manual attendance is
always retained. Browser testing must use HTTPS and verify camera permission,
retry/review/manual states, camera-track cleanup, and navigation/backgrounding.

## FACE-SETUP-01 enrollment readiness

Create/run the read-only readiness check before changing face setup:

```sh
set -a; . .env; set +a
FACE_SETUP_DB=ccc_database_test \
FACE_SETUP_REQUIRED=1 \
FACE_INFERENCE_URL=http://127.0.0.1:6120 \
php tests/attendance/FACE-SETUP-01-enrollment-readiness.php --required
```

It checks the seven face tables, provider/model/calibration registry tuple,
private model health/integrity, configured model-version ID, transport settings,
and safe enrollment diagnostic reasons. It performs only `SELECT`/health
requests. Required mode exits nonzero for any blocker. The current server
preflight passes transport, inference, calibration, PAD-deferred, and
no-write checks but reports the seven persistent face tables as missing until
the explicitly approved additive test-database setup is run.

The synthetic model adapter check uses no child or camera data:

```sh
/var/lib/creativeculturalcentre/face-inference/venv/bin/python \
  services/face-inference/tests/test_runtime_model.py
```

The inference dependencies are installed only in that application-local
virtual environment. Do not install them into system Python or change global
PHP-FPM settings.

The enrollment preview contract verifies the guide, detection pointer,
single-face instructions, frame progress, optional browser detector, and
explicit pending-calibration messaging. Run:

    node tests/attendance/FACE-ENR-UI-02-detection-overlay-contract.js
    node tests/attendance/FACE-ENR-UI-03-model-preview-contract.js

The actual private YuNet preview is bounded to one temporary frame at a time
and returns face coordinates only; it does not write enrollment, attendance,
membership, or image data.

The overlay-coordinate regression uses the exact preview box shape observed
from the Testing branch and checks the shared frame/aspect-ratio contract and
rendered-pixel mapping:

```sh
node tests/attendance/FACE-ENR-UI-04-overlay-coordinate-contract.js
```

The enrollment threshold-failure recovery contract verifies that eight
captured frames do not terminate the page when calibration is unavailable:

```sh
node tests/attendance/FACE-ENR-UI-05-calibration-recovery-contract.js
```

The face-attendance session contract verifies that every lifecycle request
passes the application session identifier required by `session::check_session`:

```sh
node tests/attendance/FACE-API-02-session-contract.js
```

The corrected face-attendance requests use the current application session;
they do not create a second authentication mechanism. A `SESSION_REQUIRED`
response still means the browser session has expired or the page was opened
without a valid application session and requires a fresh login.
The selected attendance session is carried separately as
`attendance_session_id`, so it cannot overwrite the application session field.

Enrollment and model calibration are separate workflows. Calibration requires
approved score-only genuine/impostor records from held-out real-device tests;
the eight enrollment frames are temporary and are discarded after a failed
verification. Generate the approved artifact with `services/face-inference/tools/calibrate.py`,
load it through `FACE_CALIBRATION_FILE`, verify the private health endpoint
reports `calibration_approved=true`, and only then restart face mapping.

## FACE-OUT-01 punch-out activation

The punch-out control is feature-gated by `FACE_PUNCH_OUT_ENABLED` and sends
`PUNCH_OUT` through the existing shadow scan lifecycle. Run its focused UI
contract with:

```sh
node tests/attendance/FACE-OUT-01-punchout-ui-contract.js
```

The Testing branch configuration currently hides the button with
`FACE_PUNCH_OUT_ENABLED=0`. If re-enabled for a controlled test,
`FACE_ATTENDANCE_LIVE_WRITE=0` still prevents face scans from writing
attendance or membership balances.

## FACE-ENR-UI-01 Testing child mapping

When `FACE_ENROLLMENT_ENABLED=1`, each member in the loaded attendance roster
gets a `Map face for testing` action. An authenticated teacher starts setup,
the browser creates a reference automatically when needed, captures the
enrollment frames, and stores encrypted templates. After a successful setup,
the teacher can immediately choose `Set Up Another Face` or `Start Attendance
Scan`; the latter begins supervised punch-in scanning. This is per-child
enrollment, not model threshold calibration.

```sh
node tests/attendance/FACE-ENR-UI-01-mapping-contract.js
```

The enrollment lifecycle test verifies the intended same-child uniqueness
boundary: the first mapping stores three encrypted templates from one capture,
while a second active enrollment is rejected without another inference call.

```sh
ATTENDANCE_TEST_DB_USER='test_user' \
ATTENDANCE_TEST_DB_PASSWORD='test_password' \
php tests/attendance/FACE-ENR-01-enrollment.php --required
```

## FACE-UI-09 next-child gate

The continuous scanner remembers an identified child, waits for the preview to
see zero faces and then exactly one next-child face before attempting again,
and suppresses repeated-member popups. Multiple faces do not release the gate.
Stop/cancel clears the wait timer so a later scan can start cleanly.

```sh
node tests/attendance/FACE-UI-09-next-child-gate-contract.js
```

## FACE-ENR-02 cross-member duplicate identity

This disposable-database regression proves that a face already assigned to
another child in the same branch is rejected before consent/template writes,
including a template-level collision hidden by a cancelled centroid. A distinct
face remains enrollable, and another branch is outside the comparison scope.

```sh
ATTENDANCE_TEST_DB_USER='test_user' \
ATTENDANCE_TEST_DB_PASSWORD='test_password' \
php tests/attendance/FACE-ENR-02-cross-member-duplicate.php
```

`FACE-ENR-02-template-audit.php` is the read-only deployment audit. Supply the
database, branch, and embedding-key values through environment variables. It
prints scores only; it does not write or expose biometric templates.

## FACE-ENR-13 early setup duplicate decision

The early setup check keeps similarity from `0.75` to below `0.90` advisory.
It stops before pose capture only when two distinct quality-valid frontal
observations identify the same other enrolled child at `0.90` or above and
meet the active policy's template-support and margin requirements. The active
policy must be supplied by the inference service and match the model and
calibration version; unavailable policy/index/capture evidence fails open for
capture with a warning. The final two-burst check remains authoritative.

The `0.90` value is project configuration, not a universal SFace standard.
The checked-in held-out and field datasets contain 10 and 20 comparisons,
respectively, so they are not enough to establish a production false-match
rate.

```sh
php tests/attendance/FACE-ENR-13-early-identity.php
```

## FACE-TEST-ENV-01 disposable end-to-end environment

Use `tests/face-test-env/` to run the required PHP lifecycle tests against
isolated MySQL databases without touching the application database:

```sh
cp tests/face-test-env/.env.example tests/face-test-env/.env
tests/face-test-env/run.sh up
tests/face-test-env/run.sh test
tests/face-test-env/run.sh down
```

The environment forces shadow mode, disables live writes and punch-out, and
refuses non-test database namespaces. `destroy` requires an explicit opt-in to
remove its named volume. The contract itself is checked with:

```sh
bash tests/attendance/FACE-TEST-ENV-01-contract.sh
```

## FACE-API-09 supervised shared API

The v1 contract is published at `docs/api/face-attendance-v1.json`. The focused
contract verifies the provider abstraction, provider/model-name/model-ID/policy
registry binding, version-compatible templates, teacher-commit boundary,
PAD-deferred status, shared web route, status/resume, idempotency, HTTPS,
origin restrictions, and CWD-independent controller bootstrap:

```sh
php tests/attendance/FACE-API-09-supervised-provider-contract.php
```

A standard OpenAPI 3.1 description for generated web/Android/iOS clients is
published at `docs/api/face-attendance-v1.openapi.json`. It freezes the shared
authentication headers, operation names, request shapes, provider metadata,
and the configured response invariants (`PAD=DEFERRED`, automatic no-match continuation,
shadow `SELECT_PRESENT`, and live `ATTENDANCE_RECORDED`/`ALREADY_PRESENT`). Verify it
The multipart schema distinguishes one-to-three-frame recognition attempts
from automatic seven-to-ten-frame enrollment. Verify it with:

```sh
php tests/attendance/FACE-API-10-openapi-contract.php
```

`tests/face-test-env/run.sh test` initializes the minimal disposable parent
schema, applies both face schema migrations, and then runs the Python suite,
manual-attendance regressions, attendance-domain idempotency scenarios, and
the PHP scan/enrollment/duplicate lifecycle suites.

For an explicitly approved persistent setup of the current-server test
databases, use the exact-name-guarded additive installer. It refuses partial
schemas and never drops, deletes, or overwrites an existing model row. Run it
from an environment that supplies `FACE_MODEL_VERSION_ID`,
`FACE_CALIBRATION_POLICY_VERSION`, and any non-default provider metadata; all
artifacts, settings, parent tables, and existing registry compatibility are
validated before the first DDL statement:

```sh
php tools/setup_face_test_databases.php          # read-only dry run
php tools/setup_face_test_databases.php --apply  # explicit persistent change
```

`FACE-TEST-DB-02-setup-contract.php` verifies those safety boundaries. This
tool accepts only `ccc_database_test` and `ccc_central_test`; it cannot target
the production database.

## FACE-PAD-01 liveness and active challenge

Gate 3 is deferred. The provider still reports its PAD evidence, and `SPOOF`
remains rejected while `CHALLENGE` remains review only. A non-`LIVE` liveness
result cannot produce an accepted face attendance write. The browser contract continues to verify the optional
randomized head-turn state, challenge-token forwarding, and accessible manual
fallback:

```sh
php tests/attendance/FACE-PAD-01-liveness-gate.php
node tests/attendance/FACE-PAD-01-challenge-ui-contract.js
```

The Python suite additionally covers PAD model preprocessing, approved
calibration coverage, single-use challenge tokens, repeated frames, replayed
batches, and per-scan attempt limits. Physical fixture scores are operational
evidence and must not be replaced by these synthetic contract tests.
