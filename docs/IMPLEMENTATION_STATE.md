# Automatic browser face implementation

**Current status — October 10 Android verification: physical-camera attendance for member623 is VERIFIED (attendance/event2, session2), with physical close/reopen and repeat protection. A demonstrated fresh-capture duplicate-check bug is fixed; all eight recorded morning attempts now reject in temporary-table tests. Only623 remains ACTIVE; production matching remains disabled. See the final Android verification section below, which supersedes earlier NOT TESTED statements.**

Product direction: [PRODUCT_GOAL.md](PRODUCT_GOAL.md) is the permanent alignment reference. Read it before major changes; report usable behavior, product improvement, live blocker, specific engineering next action and remaining deployment evidence at every milestone.

Current phase: embedded workflow implemented; pilot label correction and public evaluation completed. Investigate observed recognition misses and false acceptance using the existing pipeline. One real person is available; independent production validation remains BLOCKED. Isolated pilot live attendance has succeeded. Production matching remains disabled. The final continuation below supersedes earlier historical status/next actions.

Repository inspection (2026-10-09): master; existing PHP/AngularJS frontend (plain JS, no build), PHP controllers/services, MySQL/InnoDB, session authentication and Attendance permission. Existing YuNet INT8 and SFace INT8BQ ONNX assets and Python reference encoder are retained. Existing attendance domain owns membership deductions and record uniqueness. Existing guided flow does not implement the browser-embedding flow described in the supplied decision guide.

Pre-existing edits: pf/attendance.php, pf/js/controllers/batchAttendanceController.js; untracked supplied decision guide. Preserve these edits.

Contract: authenticated same-origin browser page; automatic five-observation capture; completed enrollment template and attendance observation batch submitted to PHP. One recognition model, SFace, RGB float 0..255 NCHW 112x112, five-point similarity alignment, 128-dimensional L2 vectors. Versions bound by the shared configuration. No development-mode identity bypass. Missing representative calibration disables enrollment identity decisions and attendance acceptance. Existing encrypted face_template and AttendanceDomainService reused. No production database migration or service deployment during implementation.

Test sequence: phase 1 preprocessing/normalization/real ONNX browser parity and camera safety; phase 2 aggregation/cancel/restart; phase 3 transactional duplicates/idempotency/authorization; phase 4 unknown/conflict/eligibility/uniqueness/retry; phase 5 real browser/backend integration and delivery instructions. External live-person/device checks remain blocked and do not stop independent executable checks.

Phase 1 passed: pure preprocessing/normalization/overlay/quality/decoder checks; Chromium camera startup and cancellation; real YuNet/SFace WASM inference on public photo, blank, two faces and portrait fixtures. Identical-raster SFace comparison to Python encoder cosine 0.99995137. First measured inference: detection 364.5 ms, recognition 144 ms on this server's headless Chromium; not device performance. Initial test failures (missing browser libs, test-server root, comparing differing resize paths) resolved. Browser assets prepared from checksum-verified existing weights; no replacement model.

Blockers: representative consent-based labeled embeddings and physical cameras/devices unavailable. No thresholds may be inferred from the guide's fixture measurements.

Phase 2 passed: automatic five-observation public-photo simulated camera, deterministic aggregation with outlier exclusion, zero/multiple-face reset, stop, second enrollment capture with stable template cosine >0.999. Three Chromium tests passed; measured capture batch 4545.6 ms, camera startup 92.1 ms, last detection 330.8 ms, last recognition 92.5 ms (headless server only).

Phase 3 added: BrowserFacePolicy, BrowserFaceService, cookie/CSRF authorization controller/route, additive migration for model binding and idempotency/audit/rate table, API contract. Reuses encrypted face_template and biometric_consent; all tenant duplicate mutations serialize via MySQL advisory lock and enrollment save/idempotent outcome share one transaction. No production schema applied. Test-only constructor injection tests calibrated acceptance; there is no runtime development bypass.

Phase 3 passed: 21 backend assertions (temporary tables in ccc_database_test), six request authorization checks. Cross-connection advisory lock contention and rollback after rejected insert verified; simultaneous persisted enrollment race also passed in the final isolated integration DB run.

Phase 4 backend passed: 17 assertions for actual AttendanceDomainService transaction, repeated/unknown/conflicting/ambiguous faces, one membership deduction, actor and scheduled-session isolation, two independent sessions, committed-event recovery after lost journal/closed camera, inactive and revoked identities. Browser UI added at pf/face.php; existing attendance entry actions lead to that automatic flow. Legacy endpoints retained for compatibility; no new native app.

Phase 4 frontend/backend passed (2026-10-09): four Chromium tests, 49.6 seconds total. Actual cookie session and central Attendance permission, automatic public-photo enrollment, duplicate rejection, actual persistent isolated MySQL fixture, attendance response deliberately dropped after commit and recovered by identical retry, repeat scanning and fresh camera session preserve one attendance/event and one deduction; CSRF and unauthorized person rejected. Test-only service constructor injection uses fixture threshold 0.99; production calibration stays disabled. This is controlled workflow evidence, not live-person accuracy or physical camera evidence.

Phase 5 verified: calibration evaluator (four unit tests); seven report-bound policy checks; migration repeatability/contract/unique request key tested against the real face schema on temporary tables. JS math/preprocessing/aggregation and transport retry/cancellation tests pass. Backend now has 41 assertions, including lease expiry under timezone mismatch, rate limiting and idempotent recovery after rate limiting. Eight browser scenarios verified across the complete suite and targeted calibration/API run: actual WASM inference/parity, camera lifecycle/denial/timeout, automatic enrollment and stable restart, 25-observation repeated inference, full authenticated PHP/MySQL workflow, simultaneous enrollment (one accepted, one duplicate), real distinct and unknown public faces, lost attendance response/retry, private calibration export with unchanged saved counts, mobile-width layout, anonymous API and malformed input rejection. Production responses are never mocked as identity success.

Measured final controlled run (headless Chromium 145 on this server; public-photo simulated camera): capture batch 4572.3 ms; camera startup 88 ms; 25-frame repeated run detection P50/P95 320.8/340.6 ms; recognition P50/P95 92.4/105.9 ms. Three real PHP attendance round trips: 34.05, 9.10, 25.04 ms with a tiny fixture roster, including lost-response recovery. Renderer RSS before model load 78912 KiB and after 25 observations 283064 KiB; browser RSS 88896 to 94272 KiB. RSS includes shared pages and cannot be summed as unique total application memory. Page-only JS heap API reported a coarse 10000000 bytes and excludes native/worker memory. This short repeated run does not establish long-session memory stability, low mobile CPU usage or physical-device performance. Reusable sessions/buffers, single in-flight frame, disposed tensors, closed bitmaps and terminated camera/worker verified.

Files added/changed by this task: pf/js/browser-face/{contract.json,core.mjs,worker.mjs,capture.mjs,api.mjs,app.mjs}; pf/face.php; pf/css/browser-face.css; controllers/browserFace.php; routes/browserFaceRoute.php; classes/services/{BrowserFacePolicy.php,BrowserFaceService.php}; database/migrations/20261009_01_browser_face.sql; scripts/prepare_browser_face.py; evaluation/{calibrate_thresholds.py,test_calibrate_thresholds.py}; tests/browser-face/ (logic, transport, PHP backend/auth/policy/migration, camera/inference/enrollment/performance/workflow/calibration tests, guarded fixture helpers and README); package.json/package-lock.json; .gitignore; services/face-inference/browser-face.env.example; docs/api/browser-face.md; docs/BROWSER_FACE_SETUP.md; docs/BROWSER_FACE_CALIBRATION_REPORT.md; this state file. Focused entry-action additions in the pre-edited attendance page/controller preserve the user's existing changes. Prepared ignored model/runtime assets actually exist in pf/vendor/browser-face; no raw images/biometric datasets were added to the repo.

Model contract: sface-int8bq-fb143eea; preprocessing yunet640-similarity5-rgb255-v1; source SHA-256s and preprocessing are centralized in contract.json. Browser resize/alignment pipeline is versioned separately from older Python/guided templates. Global enrollment duplicate protection blocks incompatible active templates rather than silently omit them. Once its registry is installed, controllers/faceEnrollment.php rejects legacy enrollment/reference-creation/admin-activation writes so old clients cannot bypass the browser duplicate boundary; status/deletion endpoints remain compatible. Installation/retirement detection tested on temporary registry tables. No additional recognition model or production test-mode acceptance toggle.

Known blockers/limits: no representative live calibration dataset, no live consenting-person validation, no physical desktop/Android/iPhone camera tests, no long-session or representative scale benchmark; no validated presentation-attack defense. Existing legacy reference portraits, if present, remain under the legacy deletion procedure. Production tenant schema/configuration was not modified and services were not restarted. Source work is on master; pre-existing user changes remain in the working tree. controllers/faceEnrollment.php also has the focused post-installation legacy-writer guard described above.

Exact outstanding operational actions:
1. Review the delivered additive migration and intended tenant/branch rollout; back up and apply only with deployment authorization. Reconcile incompatible old templates through an authorized migration/deletion/re-enrollment decision, never silently erase them.
2. Follow docs/BROWSER_FACE_SETUP.md to collect private consent-based observations across independent visits/devices/lighting, evaluate with evaluation/calibrate_thresholds.py, choose acceptable error rates and independently validate actual workflows.
3. Install a protected approved policy and matching hashed evaluation report only after that evidence exists. Production identity acceptance remains disabled meanwhile; no fabricated thresholds or development bypass.
4. Perform the physical-device checklist on desktop Chrome, Android Chrome and iPhone Safari, measure long sessions and scale, then review operational/spoofing limitations before an authorized release.

Final cleanup completed: owned isolated integration database and its scoped grant removed. Public test photos/runtime prerequisites remain in /tmp for repeatability; no live biometric data was used. Independent JS executions confirm 8 math/capture cases and 3 transport cases. Attendance lease expiration uses the database clock consistently, including a PHP/MySQL timezone-mismatch regression. Next: follow the outstanding operational actions above. Do not restart completed phases or replace SFace.

## Live camera test preparation — 2026-10-09

User requested testing with their camera on the live CCC site, then explicitly selected the Test branch and requested a testing batch/members for multi-face checks. Located `ccc_database`, branch 2 (`Testing`). This authorized rollout supersedes the earlier statements that no production migration/service reload had been performed.

Live HTTPS checks found `.mjs` returned as `application/octet-stream`, preventing ES-module execution. Added `mjs` to the existing JavaScript mapping in `/etc/nginx/mime.types`, backed up that file at `/etc/nginx/mime.types.ccc-face-backup-20261009-145822`, validated `nginx -t`, and reloaded Nginx. Application, worker and ORT ESM now return HTTP 200 `application/javascript`; WASM returns `application/wasm`; both real ONNX assets return HTTP 200. Existing unrelated duplicate-server-name warning was reported by Nginx; validation succeeded.

Backed up `ccc_database` with single-transaction mysqldump at `/tmp/ccc-face-pretest-20261009.sql` (105392297 bytes, mode 0600), then applied only `database/migrations/20261009_01_browser_face.sql` to that tenant. Its registry remains PENDING_CALIBRATION. The installed registry also enables the documented tenant-wide legacy enrollment writer guard; existing templates were preserved.

Created batch `Face Camera Test 2026-10-09` (id 33), private and absent from public-web listings, branch 2, active 2026-10-09 through 2026-11-08. Daily sessions 09:00–21:00 and weekly batch-time rows support physical testing. Three new, clearly named test members were created, with blank communication details, no member-login creation, no notifications, and zero-value testing membership invoices:

| Name | Member | Contact | Code | Invoice | Item |
| --- | --- | --- | --- | --- | --- |
| Face Camera Test One | 623 | 791 | 1623 | 1178 | 1188 |
| Face Camera Test Two | 624 | 792 | 1624 | 1179 | 1189 |
| Face Camera Test Three | 625 | 793 | 1625 | 1180 | 1190 |

Actual read-only service verification passed: all three members and batch visible; one scheduled session today (session id 1); eligible roster exactly 623,624,625; existing active templates still 3; new members have zero templates; request journal empty. Existing encryption key and key-ID configuration present; values were not logged. Creation was transactional and no previous attendance/member/template rows were changed.

Current immediate action: user signs into Testing and opens `/pf/face.php?contact_id=791` to test the real camera. Multiple faces must pause collection, not mark multiple identities. Capture/consented calibration collection can be tested; enrollment and attendance identity acceptance remain disabled with no development bypass. Existing incompatible active templates remain a later enrollment blocker and require an explicit migration/re-enrollment decision, not silent deletion. Live camera results must be recorded when the user supplies them; none are claimed yet.

## Final attendance integration and collection rollout — 2026-10-09

This section supersedes the separate-page entry flow and calibration-download description above. Current phase: final verification and human-required live calibration/legacy authorization. Continue this implementation; do not restart earlier phases or substitute models.

Implemented enrollment and continuous Scan Arrivals directly inside attendance.php using a shared AngularJS bridge/modal. The selected row and existing batch/date/time are passed automatically. Confirmed API results patch the existing row without reloading. Repeated attendance increments session usage only once. Closing, changing context, navigation, cancellation and scope destruction stop the camera/worker; generation fences reject stale callbacks. Uncertain submissions retain the original payload/idempotency key for recovery. face.php is a compatibility redirect; obsolete standalone app.mjs and ordinary guided enrollment/admin-review markup were removed. Existing manual attendance and optional departure functionality remain.

Added a separate minimal operator calibration collector using the same worker/capture pipeline, requiring explicit consent and Attendance write permission. Encrypted aggregate observations live in face_web_calibration, never active face_template/attendance. Metadata-only progress, consent withdrawal, 30-day expiry, private mode-0600 CLI export outside the webroot and explicit expiry purge are implemented. Daily purge scheduling remains an operational step; expiry excludes records but does not itself erase idle database ciphertext. Evaluator requires independent calibration/validation visits for every identity and reports insufficient validation instead of inventing thresholds.

User explicitly approved applying only database/migrations/20261009_02_browser_face_collection.sql to ccc_database. Fresh backup: /tmp/ccc-face-precollection-20261009.sql, 105400704 bytes, mode 0600. Migration applied successfully; template 94–96 statuses/ciphertext fingerprints and attendance/event counts verified unchanged. Schema is tenant-wide, while collection/status/deletion use authenticated branch scope. Duplicate checks and incompatible-template protection remain tenant-wide. No other tenant was migrated and no live threshold was installed.

Legacy inspection: templates 94,95,96 all belong to ONE identity, member 616 / contact 774 / branch 2 (FaceTest01 Child), model registry 2, preprocessing sface-opencv-aligncrop-rgb-raw-v2. Reference 103 exists encrypted and decrypts to 1280x720; only dimensions/readiness were inspected in memory, with no exported raw image. Availability does not prove preprocessing compatibility. Fresh-capture migration uses an exact member/branch/template-ID/ciphertext-fingerprint allowlist, protected approval file and valid calibration; duplicate checks include all compatible tenant templates and refuse another incompatible identity. Replacement, SUPERSEDED legacy statuses, retained ciphertext/history, audit and idempotent result commit atomically. Failed operations restore the old active state. Explicit CLI rollback checks unchanged state, preserves attendance and restores the compatibility gate. No live replacement/rollback has been authorized or executed.

Focused files changed/added in this continuation: pf/attendance.php; pf/js/controllers/batchAttendanceController.js; pf/face.php; pf/face-calibration.php; pf/inc/browser-face-panel.php; pf/css/browser-face-embedded.css; pf/js/browser-face/{embedded,attendance,calibration}.mjs; removed app.mjs; classes/services/BrowserFaceService.php; controllers/browserFace.php; routes/browserFaceRoute.php; controllers/faceEnrollment.php; database/migrations/20261009_02_browser_face_collection.sql; scripts/browser_face_admin.php; evaluation/{calibrate_thresholds,test_calibrate_thresholds}.py; services/face-inference/browser-face.env.example; tests/browser-face/{embedded.test.mjs,embedded.php,workflow.spec.cjs,calibration.spec.cjs,z-continuous.spec.cjs,backend.php,migration.php,prepare_public_fixture.py,create-integration-fixture.php,README.md}; docs/{api/browser-face.md,BROWSER_FACE_SETUP.md,BROWSER_FACE_CALIBRATION_REPORT.md,IMPLEMENTATION_STATE.md,BROWSER_FACE_FINAL_DELIVERY.md}.

Final executable checks so far: backend 70 PASS assertions using only connection-scoped temporary tables in ccc_database_test; authorization 6 PASS; report-bound policy 7 PASS; both migrations repeat-safe on temporary tables; evaluator 4 PASS; JS 3 test files / 15 cases PASS; changed PHP files and attendance controller syntax PASS; git diff --check PASS. Full browser result is recorded below when complete. No production API returns a fixture identity result. Public-photo browser tests use actual YuNet/SFace WASM, PHP transactions and persistent isolated MySQL regression fixtures; these are code/transaction evidence, not live biometric calibration.

Model/preprocessing unchanged: sface-int8bq-fb143eea / yunet640-similarity5-rgb255-v1. No new frontend framework, inference service, recognition model or attendance table/domain replacement. Existing AttendanceDomainService, member eligibility/branch permission/session rules, membership deductions and attendance_event remain authoritative.

Exact next actions after executable verification: collect actual consented people at /pf/face-calibration.php; export privately and evaluate at least 20 identities/100 representative aggregate observations across separate visits/splits/devices/lighting; review acceptable false-accept/false-reject trade-offs and independently validate; authorize/install only the actual report-bound calibration policy. Then separately authorize member 616's exact migration, capture and replace it, confirm no incompatible ACTIVE templates remain, and run the real Testing branch 2 / batch 33 workflows with members 623,624,625. Physical desktop Chrome, Android Chrome, iPhone Safari, full authenticated attendance page and long-session tests remain unverified. Do not retire templates 94–96, alter production thresholds or deploy destructive operations without explicit authorization.

Final full Chromium result: **9 passed (2.4 minutes)** against a fresh owned isolated fixture. Sequential camera test confirmed two distinct real-model identities, two attendance rows/events, same uninterrupted capture generation and unknown rejection. Earlier dirty-fixture rerun failed count-delta expectations because the prior attempt had already marked those members; rebuilding the owned fixture resolved it without changing acceptance thresholds or assertions. Shared real production Angular bridge/panel were tested in the harness; the entire authenticated attendance.php shell was not exercised. Owned fixture/grant removed after the passing run.

Final performance (public-photo simulated camera, headless server only): capture 4799.3 ms, startup 91.5 ms; 25 observations detection P50/P95 318.6/350.7 ms, recognition 91.8/99.7 ms; PHP attendance round trips 30.62/16.59/21.98 ms. Renderer RSS 79672→283852 KiB; browser 88696→94072 KiB, shared-page caveat applies. No physical-device/long-session claim.

Final read-only live verification: branch 2/batch 33/session 1 still visible; eligible members 623/624/625 have zero saved templates; calibration zero identities/samples/validation; templates 94–96 remain ACTIVE and unauthorized; identity_acceptance_ready=false. See docs/BROWSER_FACE_FINAL_DELIVERY.md for all 14 acceptance statuses, exact evidence and remaining human steps.

## Permission simplification — 2026-10-09

User clarified that Attendance page permission must cover all face actions with no additional permission overhead. Removed the extra Attendance level-2 check for calibration/migration actions; all browser face actions now use the single existing Attendance level-1 access check. The calibration page uses the same access. Enrollment/scanning already used that check. No new roles or permission tables; authenticated branch/member/session rules still apply. Calibration evidence and the explicit legacy-template replacement allowlist are operational safeguards, not separate operator roles, and were not bypassed or installed by this permission change.

Files: controllers/browserFace.php, pf/face-calibration.php, routes/browserFaceRoute.php, tests/browser-face/router.php (fixture operator selection matches Attendance page access), and current API/setup/calibration/delivery docs. Verification: six browser-request authorization checks passed, changed PHP syntax passed, git diff --check passed. No database, calibration policy or live template mutation was performed. Prior full-browser results above predate this small permission change; no physical/live acceptance claim is added.

Attendance control cleanup: user requested removing Finish Without Scanning. Removed its button from pf/attendance.php (including its alternate Mark Attendance Manually label). Other attendance controls/domain logic remain intact. Verification: PHP syntax and git diff --check passed; no database changes.

Legacy UI cleanup: user requested removing legacy enrollment and the authorized-replacement message. Removed Replace legacy face and its explanatory message from attendance.php, along with the outdated hidden-controls comment. Current Enroll Face/Face ready controls remain. Existing legacy template records and backend compatibility/duplicate protections were not changed. Verification: PHP syntax and git diff --check passed; legacy button/message absent from the attendance page source.

## Matching setup feedback correction — 2026-10-09

User reported the confusing post-capture Live face calibration message and requested immediate enrollment/scanning. Read-only live inspection reconfirmed zero calibration observations, unset validated matching configuration, zero templates for test members 623–625 and three incompatible ACTIVE templates 94–96. No matching report exists in the repository. User answered that no existing dataset/report is available and chose collection through the existing calibration screen. Actual consenting people/cameras are required; agent has not collected their observations or enabled live acceptance.

Fixed the actual UX failure: enrollment/scanning check fresh configuration before camera capture and explain incomplete one-time matching setup, instead of collecting a full template then failing. Configuration refreshes at every camera opening so a subsequently approved policy is picked up without stale disabled readiness. Calibration collection stays available without live identity acceptance and uses the same automatic real-model pipeline. Backend/config messages consistently explain the missing setup and state that nothing was saved. No invented thresholds, weakened policy, development mode, live legacy retirement or permission overhead was added.

Files: classes/services/{BrowserFacePolicy,BrowserFaceService}.php, controllers/browserFace.php, pf/js/browser-face/embedded.mjs, tests/browser-face/embedded.test.mjs, docs/api/browser-face.md and this state. Tests: JS three files/17 cases passed (new pre-capture blocker and configuration refresh cases), authorization six passed, policy seven passed, backend 70 assertions passed; two calibration browser scenarios passed in 7.2 seconds with real inference/encrypted isolated MySQL saves and API boundary checks. Changed PHP syntax and git diff --check passed. A shell quoting error interrupted the initial targeted test invocation; corrected by passing the test filename directly. The succeeding run is the reported evidence. Owned fixture removed afterward.

Next: operator opens /pf/face-calibration.php on Testing branch 2, selects actual consenting identities and collects real labeled visits/device/lighting/split observations; continue private export/evaluation/independent validation and approved measured configuration. Dataset remains zero until people perform collection. Legacy compatibility condition remains a separate unresolved enrollment blocker after calibration. Do not claim live enrollment or scanning operational.

## Attendance-integrated collection and authorized old-template erasure — 2026-10-09

User explicitly authorized removal of the old templates and requested calibration collection directly from attendance.php without a separate operator calibration flow. That authorization supersedes the earlier no-retirement restriction ONLY for ccc_database / branch 2 / member 616 / template IDs 94,95,96.

Completed automatic setup collection through the existing Enroll Face row action and shared camera/worker. A displayed consent notice makes the operator's click explicit confirmation; selected-member context is passed automatically. When identity acceptance is not ready, the same enroll endpoint saves an encrypted aggregate to face_web_calibration and returns ENROLLMENT_PENDING. The row updates immediately and preserves pending status across reload. No active template, guessed identity or attendance is created. Device metadata is hashed from actual camera/browser settings; face brightness supplies measured lighting buckets. Server capture dates supply visit labels; first two distinct dates are calibration and later dates validation. Same-day repeats retain their original split and never count as independent visits. Independent review/validation is still required; inferred metadata is not proof of device/lighting representativeness. Once valid configuration exists, ordinary Enroll Face returns to central duplicate checks. Pending samples alone are not active enrollments. Unknown scanning observations are not labeled automatically.

Added CLI retire-templates with explicit member/branch/ID/fingerprint/authorization, exact active legacy-set checks, tenant lock, transaction, safe already-erased retry and audit metadata. It revokes/erases only inspected incompatible embeddings and preserves their metadata, references and attendance. Scope/fingerprint/retry/encryption/date-split tests pass before live use.

Fresh single-transaction backup: /tmp/ccc-face-preretirement-20261009.sql, 105437096 bytes, mode 0600. Executed authorized exact retirement using inspected fingerprint d498143130c9a1f345e3848573cdd49eb9d66b7eb0634cb10b27bc2beb3c3a35. Final read-only verification: 94/95/96 REVOKED with zero ciphertext bytes, no incompatible ACTIVE templates, attendance/event counts and original reference 103 fingerprint/status unchanged. Audit reason USER_AUTHORIZED_LEGACY_RESET / label user-request-20261009 stored in quality_summary. No other template/member/branch was modified. Erased vectors can only be restored from the private backup with explicit recovery authorization; do not run ordinary migration rollback for these erased rows.

Live operator collection progressed during this work: final check found 14 observations for member 623, one identity, zero validation observations, same visit label Test, and supplied Mac/Android/Bright labels. These were collected by the operator, not fabricated or inserted by the agent. They are insufficient independent calibration evidence. Test members 623/624/625 still have zero active templates. identity_acceptance_ready remains false. Enrollment collection now works from attendance.php; live identity acceptance/attendance is not yet operational or claimed VERIFIED.

Files changed in this continuation: classes/services/BrowserFaceService.php; controllers/browserFace.php; pf/attendance.php; pf/js/controllers/batchAttendanceController.js; pf/js/browser-face/{attendance,embedded,capture,worker}.mjs; scripts/browser_face_admin.php; tests/browser-face/{backend.php,embedded.test.mjs,router.php,embedded.php,a-pending.spec.cjs}; docs/{api/browser-face.md,BROWSER_FACE_SETUP.md,BROWSER_FACE_CALIBRATION_REPORT.md,BROWSER_FACE_FINAL_DELIVERY.md,IMPLEMENTATION_STATE.md}. No new database schema, application or inference implementation.

Tests: backend 85 PASS assertions, JS three files/18 cases PASS, authorization six PASS, report-bound policy seven PASS, evaluator four PASS, changed PHP/controller syntax and git diff --check PASS. Full updated Chromium suite: **10 passed (2.5 minutes)**, real-model encrypted automatic attendance-panel collection, pending row/reload status, no false active enrollment/attendance, setup-blocked scanner, consent/authorization/retries, duplicate/concurrent/distinct/unknown, uninterrupted two-person scan and cleanup. First browser run failed because the new test selected member 3, intentionally absent from that fixture batch; corrected to eligible member 1 and reran on a fresh fixture, with unchanged eligibility rules/thresholds. Owned fixture/grant removed after success. Production permission remains the single Attendance page access check.

Measured latest headless simulated run: capture 4651.8 ms, startup 91.9 ms; detection P50/P95 320.2/344.8 ms, recognition 92.4/99 ms; PHP attendance round trips 32.52/10.75/23.02 ms. Renderer RSS 78520→284112 KiB, browser 88120→93624 KiB, shared-memory caveat unchanged. Physical cameras, full authenticated attendance shell and live-person matching accuracy remain unverified by the agent.

Next: refresh attendance.php, use the selected consenting members' Enroll Face actions to collect distinct people and representative independent days/cameras/conditions automatically; review the private dataset, held-out evaluation and real workflow evidence, then install only validated report-bound thresholds. Legacy blocker is resolved. Do not invent thresholds, activate pending observations without duplicate checks, guess identities from unknown scans, or claim live attendance acceptance before that configuration exists.
# Lifecycle, evidence and isolated-pilot continuation — 2026-10-09 (in progress)

The current implementation preserves PHP/MySQL/AngularJS, YuNet/SFace, the shared browser worker, and AttendanceDomainService. No production acceptance configuration has been enabled.

Implemented: additive migration 03 for persistent PENDING/ACTIVE/REJECTED_DUPLICATE/RECAPTURE_REQUIRED candidates and encrypted full observation batches; server-side aggregation verification; consented selected-member labels; server-verified attendance-context eligibility; separate-date validation capture; atomic tenant-wide active/pending conflict comparison with branch-scoped activation; idempotent recovery; deletion and retention handling; row status updates and a small optional Check face action inside attendance.php. Validation samples cannot overwrite pending training templates. Production policy additionally requires complete-decision evaluation and independent workflow acceptance.

Actual tests so far: lifecycle.php 114 assertions passed (85 existing + 29 new); evaluator 6 unit tests passed; policy 9 checks passed. These tests use synthetic vectors or metadata solely in isolated regression fixtures, not calibration evidence. Migration 03 has not yet been applied to the live tenant. Independent real-person and physical-device validation remain unavailable.

Changed files: BrowserFaceService/Math/Policy, browserFace controller, browser-face API contract, migration 03, embedded/attendance frontend modules, attendance.php, batchAttendanceController status refresh, private admin export/purge/activation commands, evaluation scripts, lifecycle/policy/evaluation/migration tests.

Next actions: implement and verify the physically separate allowlisted pilot database and guarded configuration; update browser fixture schema and executable workflows; rerun all relevant tests; inspect actual private evidence and generate an honest report; document deployment and consented validation steps. Pilot results must not be described as production acceptance.


## Final lifecycle, evidence, evaluation and pilot delivery — 2026-10-09

This entry supersedes the preceding in-progress entry. Work remains on master; existing user changes preserved. No commits were attempted because .git is read-only in this workspace. No new model, native application, inference service, framework or attendance domain was introduced. Ordinary actions remain inside attendance.php with existing Attendance access.

Completed implementation: persistent encrypted PENDING/ACTIVE/REJECTED_DUPLICATE/RECAPTURE_REQUIRED candidates; encrypted full-batch selected-member consented evidence and UUID/date/device/lighting/context; deterministic PHP/browser aggregate verification; optional explicitly labeled Check face without attendance/activation; independently separated date splits; validation-gallery leakage prevention; conflict-label exclusion from exports; complete duplicate-template and unanimous attendance-decision evaluation with eligible galleries, held-out known/unenrolled trials, error curves and uncertainty; protected scoped exploratory pilot; transactional tenant-wide active/pending conflict checks, branch-scoped automatic activation, policy/audit binding and crash/idempotency recovery. Fixed real camera busy-state and retry-generation bugs, including retry after changing the selected session.

Model/preprocessing unchanged: sface-int8bq-fb143eea / yunet640-similarity5-rgb255-v1. Real YuNet/SFace WASM inference is asynchronous in the existing worker. AttendanceDomainService still owns authorized eligibility, valid membership, deductions, attendance and attendance_event. Recognition is only an alternative identity input; no production attendance logic was duplicated/replaced.

Files changed in this continuation: classes/services/{BrowserFaceService,BrowserFaceMath,BrowserFacePolicy,BrowserFacePilot}.php; controllers/browserFace.php; pf/{attendance.php,inc/top.php}; pf/js/controllers/batchAttendanceController.js; pf/js/browser-face/{api,embedded,attendance}.mjs; database/migrations/20261009_03_browser_face_lifecycle.sql; scripts/{browser_face_admin,browser_face_apply_lifecycle,browser_face_pilot}.php; evaluation/{calibrate_thresholds,decision_evaluation,test_calibrate_thresholds}.py; lifecycle/pilot/policy/migration/backend/fixture/router/browser/embedded tests in tests/browser-face; services/face-inference/browser-face.env.example; docs/{api/browser-face.md,BROWSER_FACE_SETUP.md,BROWSER_FACE_CALIBRATION_REPORT.md,BROWSER_FACE_FINAL_DELIVERY.md,IMPLEMENTATION_STATE.md}; tests/browser-face/README.md.

### Actual final executable results

- Lifecycle/backend: 118 PASS assertions (85 existing + 33 additional); actual PHP/MySQL temporary table persistence, consent/contract/aggregate/expiry/deletion/version checks, active/pending tenant conflicts, atomic rollback, recovery/retry, held-out gallery protection. Output /tmp/ccc-face-lifecycle-results-20261009.txt.
- Pilot: 15 PASS checks after final dated-session provisioning change. Physically separate disposable database, protected population, source-connection/foreign-branch/member/batch/cross-schema view rejection, duplicate/distinct activation, actual AttendanceDomainService member/session/event, finite membership deduction exactly once, repeat uniqueness, local membership view usage and unchanged source attendance/event/test-membership/template fingerprint.
- Authorization 6 PASS; report-bound policy 9 PASS; evaluator 6 PASS; all three migrations repeat-safe on temporary MySQL tables; JS 3 files/19 cases PASS. Synthetic vectors/metadata in these unit fixtures are not consented evidence and never enabled live production policy.
- Final full Chromium run: **11 passed (2.8 minutes)** on fresh owned disposable integration and pilot databases. Actual YuNet/SFace ONNX WASM, camera API, PHP, persistent MySQL and AttendanceDomainService. Actual authenticated attendance.php shell tested enrollment/duplicate/scanning/row updates/close/refresh; public-photo virtual camera. Other scenarios cover PENDING persistence without false ACTIVE, consent/auth, no/multiple faces, quality, deterministic automatic collection/restart, reference cosine 0.9999513689468181, concurrent duplicates, distinct/unknown recognition, dropped committed attendance response recovery, one event/deduction, uninterrupted two-identity scanner, camera denial/cancel/timeout and cleanup.
- Initial full-page regression exposed a camera busy-state bug (fixed) and a test audit observing the original scoped URL before Playwright destination rewriting (assertion corrected to accept only that scoped URL). Full fresh final suite passed without modifying matching safeguards. No mock identity success or fabricated operational report was used.
- Changed PHP/JS syntax and git diff --check passed. Disposable fixture databases/grants and disposable pilot configuration removed after the final full run. The real protected consenting-person pilot was preserved and reverified isolated/matching_ready=false.

Final headless simulated measurements: capture 4529.7 ms, camera startup 90.4–93.2 ms; over 25 observations detection P50/P95 317.4/333.6 ms and recognition 91.7/111.9 ms; three backend attendance round trips 27.27/9.82/23.01 ms. Renderer RSS 78368→282940 KiB, browser 88208→92944 KiB, JS heap 10 MB. RSS includes shared pages; heap excludes worker/native/WASM memory. Five short batches do not establish sustained mobile performance. Physical desktop Chrome, Android Chrome and iPhone Safari remain NOT TESTED.

### Actual live scope and deployment

Fresh single-transaction private backup /tmp/ccc-face-prelifecycle-20261009.sql (mode 0600) preceded additive migration 03, applied ONLY to ccc_database. Prior journal-confirmed enrollment sample 16/member 616 was recovered as one persistent PENDING candidate. No ACTIVE enrollment or attendance was created. Standalone aggregate collection was not promoted. Migration is tenant-wide; writes/results are branch-authorized and duplicate protection remains tenant-wide.

Actual consented-capture pilot provisioned as ccc_database_face_pilot_10092026, protected owner-token configuration /tmp/ccc-face-pilot-20261009.json (root:www-data 0640), seven-day expiry, exact source ccc_database / branch 2 / batch 33 / members 623,624,625 / contacts 791,792,793 / items 1188,1189,1190. No production biometric/attendance data copied. FACE_WEB_PILOT_FILE is configured; production calibration settings remain disabled. Pilot starts with no real captured templates/attendance and provisional matching disabled. Daily dated sessions for the next seven days exist ONLY inside the pilot. Every request verifies database marker/population/dependencies; Angular pilot reads are guarded and all legacy/manual write paths/notification registration are blocked. Production identities, attendance and membership deductions cannot be changed by provisional pilot matching.

Important source fixture limitation discovered and preserved: the original source test invoices have employee_id=0; the production invoice view's counsellor INNER JOIN omits them, while the module roster includes them. Pilot local membership seeds explicitly use only the allowlisted zero-value module-roster records and reflect local invoice_item deductions. AttendanceDomainService itself is unchanged. This is not a claim that source batch 33 test invoices are production-domain eligible. No source invoices or unrelated memberships were changed.

Legacy templates 94/95/96 remain REVOKED with zero ciphertext bytes under prior explicit removal authorization. All belong to member 616/contact 774/branch 2. Current incompatible ACTIVE count=0; original reference 103 and historical attendance/event baseline verified preserved. Retained-history replacement/rollback tests remain valid; erased ciphertext recovery requires the private pre-retirement backup and explicit restore authorization.

### Actual evaluation and remaining blockers

Final read-only source check: 16 unexpired aggregate observations / 2 selected identity labels / 0 validation / 0 complete observation batches / 0 ACTIVE. One persistent pending member 616. These actual operator captures were not manufactured; labels have not been independently confirmed as distinct physical people. Existing aggregate-only batches cannot prove complete recognition decisions and were not reconstructed artificially.

Private actual report /tmp/ccc-face-evaluation-20261009-final.json and .md, mode 0600. Independent genuine pairs=0, different-label pairs=28, scores min/max/mean=0.07532024/0.93452430/0.74538023; held-out pairs and full known/unknown decision trials=0. Both production recommendations=null, approved=false, decision_evaluation.complete=false. Preliminary 20 identities/100 aggregates, independent calibration/validation dates, complete batch/context, unknown-person evidence and reviewed acceptable error budgets are missing. Exploratory source report cannot enable pilot (wrong database/member 616 outside allowlist). Plaintext temporary exports created for this evaluation are removed after report creation; no vectors appear in repository docs or normal logs.

Actual consenting-person pilot enrollment/duplicate/attendance has NOT been exercised. Production live matching is BLOCKED, not VERIFIED. Simulated public-photo integration is separate executable evidence. Physical cameras, real-person independent evaluation, acceptable risk approval and production rollout authorization cannot be completed by agent-generated samples. No arbitrary thresholds or general production bypass exist.

Exact next actions: user signs into Testing branch 2 at /pf/attendance.php?face_pilot=1, confirms Practice banner/batch33, captures two genuinely different consenting people twice under One/Two and labeled unenrolled observations via Three's Check face. Administrator/agent then exports/evaluates actual pilot data, installs only its guarded exploratory report, and records real known/unknown/duplicate/continuous/repeat/recovery/device results. Build representative production consented evidence through normal row actions across at least20 identities/100 aggregate captures and two calibration dates plus two later validation dates per identity, nonempty-gallery unknown trials and intended conditions. Review actual independent reports/error budgets/workflow results; explicitly approve production rollout. Valid unique pending templates then activate automatically on ready options/open, or via approved actor-scoped CLI sweep. Ambiguous templates ask for recapture; ordinary operators never have a separate calibration step. Private expiry purge/backup/export retention are documented administrative operations; no global cron was installed.

Detailed final files/reuse/results, fourteen acceptance statuses and limits: docs/BROWSER_FACE_FINAL_DELIVERY.md. Exact install/private export/pilot/report/activation/HTTPS/recovery commands: docs/BROWSER_FACE_SETUP.md. Continue here without restarting phases or substituting models.


## Final physical-validation audit — 2026-10-09

Latest objective: verify the existing application, fix only demonstrated defects, preserve the architecture and production matching gate. No reconstruction, extra model, new calibration system, schema migration, key rotation, service restart or production biometric/attendance/membership write was performed. Master confirmed. Pre-existing user changes and docs/.BROWSER_FACE_FINAL_DELIVERY.md.swp were preserved. Focused pre-change source baseline: /tmp/ccc-face-final-validation-baseline-20261009. Private database backups /tmp/ccc-face-prelifecycle-20261009.sql (105456873 bytes, 0600) and /tmp/ccc-face-preretirement-20261009.sql remain preserved.

Audit baseline: 118 backend assertions, 19 JS cases/3 files, authorization6, policy9, evaluator6 and pilot15 passed. Existing continuous scanner, model preprocessing/normalization, encrypted storage, duplicate transactions, pending activation and AttendanceDomainService were reused rather than implemented again. Source data had grown through operator collection: 20 aggregates/six selected labels, four complete batches, zero validation, five PENDING, zero ACTIVE. The protected real pilot remained empty and matching-disabled.

Verified defects and focused fixes:

1. Four earlier observations could complete a batch after the single face jumped to a non-overlapping bounding box. A failing regression reproduced it; capture now resets on non-overlap, absence/multiple faces or interruption. Frame association is geometric continuity, not semantic identity/liveness validation for a swap at the same position.
2. Similarity to any previously marked template could suppress central recognition of a new similar-looking person and falsely display Already marked. A failing regression reproduced it. Browser cache now holds only the last confirmed exact observation batch, is cleared on track loss, and lets every changed batch reach central unique/unanimous recognition. It is bounded; no browser identity guessing or new matching threshold.
3. Repeated calibration-date capture overwrote the original usable PENDING enrollment reference. A failing MySQL regression reproduced it. Original compatible/unexpired/key-valid evidence-backed pending reference is now retained across additional captures; ACTIVE template ID/ciphertext likewise remains unchanged. Explicit recapture/deletion/expiry permits replacement. No attendance-driven template adaptation.
4. Expired pending status stayed PENDING when matching was disabled. A failing regression reproduced it; authorized metadata status now reports RECAPTURE_REQUIRED without enabling matching or mutating live candidates.
5. Administrative activation sweeps reused one report/branch journal key forever, replaying old results after new captures. Each sweep now gets a fresh key; existing identity-level transactional/idempotent/restart behavior remains. New pending candidates activate under the unchanged validated policy in tests; repeated sweeps create no extra template. Live CLI activation remains blocked by real missing validation.
6. Empty attendance cameras stopped on the enrollment deadline before anyone arrived. A failing regression reproduced it; healthy attendance worker readiness cancels the collection deadline while startup/enrollment timeouts remain. Real-model browser check confirms empty scanner stays open beyond its former deadline.

Changed in this continuation: pf/js/browser-face/{capture,embedded}.mjs; classes/services/BrowserFaceService.php; scripts/browser_face_admin.php; tests/browser-face/{core.test.mjs,embedded.test.mjs,lifecycle.php,attendance-page.spec.cjs,camera.spec.cjs}; docs/{api/browser-face.md,BROWSER_FACE_SETUP.md,BROWSER_FACE_CALIBRATION_REPORT.md,BROWSER_FACE_FINAL_DELIVERY.md,IMPLEMENTATION_STATE.md}; tests/browser-face/README.md. No AttendanceDomainService changes. Contract/test matrix updated before fixes; failures reproduced and resolved without changing thresholds or gate requirements.

Final actual verification:

- PHP backend/lifecycle: 125 PASS assertions (saved /tmp/ccc-face-final-validation-backend-20261009.txt), including immutable original pending/active reference, attendance without re-enrollment on a new service instance, expired feedback, later sweeps, conflict/duplicate/version/consent/expiry rejection, rollback/retry/atomicity and existing domain behavior.
- JavaScript: 23 cases / 3 files PASS. Face discontinuity, exact-batch suppression vs similar-looking changes, empty-camera timeout, quality/overlay/alignment/normalization, stale contexts and safe retry.
- Full final Chromium: **12 passed (3.0 minutes)** after all fixes, fresh ownership-checked disposable PHP/MySQL integration and pilot fixtures. Actual attendance.php shell, real YuNet/SFace ONNX WASM, public-photo simulated camera. Enrollment/duplicate, persistence across fresh browser session, recognition without re-enrollment, no/multiple/unknown faces, distinct/concurrent identities, continuous two-person scanner, lost committed response recovery, single event/deduction, empty scanner waiting, denial/cancel/timeout and resource cleanup all passed. Previous 11-scenario run also passed before the final empty-scanner fix; the 12-scenario run is final evidence.
- Existing manual attendance characterization9, regressions3, AttendanceDomainService6, unlimited-browser contract PASS. Limited/unlimited memberships, absence settings, employee presence, inactivity, repeats, independent sessions, punch-out and old-date/branch guards preserved. Tests used only guarded temporary tables; no production changes.
- Pilot15 PASS, source attendance/event/test-membership/template-status fingerprint unchanged. Authorization6, policy9, evaluator6 PASS; three additive migrations remain repeat-safe in temporary tables; PHP/JS syntax and git diff --check PASS. These are the applicable current browser-face and attendance suites, not a claim to have run every obsolete guided-enrollment/UI/sidecar test in the repository.
- Prepared YuNet/SFace files match their manifest hashes; real WASM/Python same-raster reference cosine 0.9999513689468181. Model/preprocessing unchanged: sface-int8bq-fb143eea / yunet640-similarity5-rgb255-v1.

Final measured headless simulated capture 4542.5 ms, startup93.2–94.2ms, 25-observation detection P50/P95320.4/330.4ms and recognition92.3/111.5ms, attendance HTTP roundtrips33.43/9.02/19.43ms. Renderer RSS79672→283952KiB, browser88944→93808KiB, JS heap10MB. RSS shared pages and worker/native/WASM exclusions apply. Physical capture times and sustained/mobile performance are unavailable, not inferred from these figures.

Actual current evidence report /tmp/ccc-face-physical-audit-evaluation-20261009.json and .md, mode0600. Source counts20/six labels/four retained full batches (20 raw observations)/zero validation/five PENDING/zero ACTIVE. Independent genuine pairs0; different-label pairs98, min/max/mean0.07465607/0.97510947/0.76058408; independent validation and eligible full-query trials0. Full batches currently supply gallery references rather than independent query trials. Both recommendations=null. Six selected labels are not independently confirmed six different people; high similarity does not resolve that question. No matching accuracy/error rate was fabricated. Temporary plaintext actual export removed after evaluation.

Physical status: no /dev/video* hardware and no callable tool exposes the user's camera; no consenting participants available to the agent. A–J consenting-person enrollment/restart/genuine/duplicate/distinct/unknown/sequential/repeat/interruption tests all BLOCKED, none physically passed. Desktop Chrome/Android Chrome/iPhone Safari physical tests BLOCKED. Backend/database process restart NOT TESTED; no production service restart performed. Fresh browser session and fresh PHP service-object persistence are automated evidence only.

Final protected real pilot verification: ccc_database_face_pilot_10092026 remains isolated, captures0, ACTIVE0, attendance0, events0, matching_ready=false. Exact branch2/batch33/members623–625/contacts791–793/items1188–1190 allowlist; protected expiry2026-10-16T12:40:59.911282+00:00 (18:10:59 IST). Provisional settings remain absent until actual pilot evidence exists. No source observations were copied into pilot and no general acceptance bypass installed. Existing source invoice employee_id=0/counsellor-view limitation remains explicitly documented; pilot local test-only membership seeds preserve source and domain isolation.

Exact next human actions: sign into Testing branch2 at /pf/attendance.php?face_pilot=1, confirm Practice attendance/batch33/current scheduled session. Obtain informed/guardian consent, capture genuinely different A under One and B under Two twice initially, keep a third person unenrolled for labeled unknown checks. Notify that pilot captures are saved; evaluate only actual pilot evidence and install its guarded exploratory report, then execute/document A–J physical tests on available devices. For production, if six labels are confirmed distinct, count deficits are at least14 further identities/80 aggregates, plus complete independent known/unknown query batches, two calibration and two later validation dates per identity, intended conditions/similar-looking people and reviewed error budgets. Counts alone are not release approval. Approve independent report/workflow results and explicitly authorize production rollout. Production matching remains BLOCKED, not operational/verified. Detailed physical actions/statuses: BROWSER_FACE_FINAL_DELIVERY.md; private commands: BROWSER_FACE_SETUP.md. Do not restart implementation phases or infer thresholds from fixture values.


## Public dataset evaluation and one-person correction — 2026-10-09 (current)

This section supersedes earlier interpretations of six stored source labels or three pilot labels as distinct real people. User confirmed only one person and retained Face Camera Test One / 623.

Completed: inspected master and existing recovery baseline, read exact current source/pilot metadata, researched author datasets/licenses, implemented bounded pinned ZIP-range subset extraction, actual production-worker browser inference and no-DB PHP decision probes, development/held-out identity evaluation with distribution/error/ambiguity/uncertainty reports. No model/preprocessing, ordinary attendance interface, AttendanceDomainService, production configuration or source biometric/attendance/membership writes changed.

Pilot correction applied reversibly: database ccc_database_face_pilot_10092026 ONLY; calibration IDs 2/3 (624/625) soft-excluded, associated evidence LABEL_CONFLICT and candidates RECAPTURE_REQUIRED / LABEL_EXCLUDED; all encrypted original records preserved. Retain IDs 1/4 under member 623. Exactly two usable captures/ten observations/one real label/zero independent validation, one PENDING candidate, zero ACTIVE, zero attendance/events. Repeat aggregate cosine 0.9211729884. Protected fingerprinted before-state audit and append-only action record /tmp/ccc-face-pilot-label-exclusion-20261009.json(.events.jsonl). Guarded rollback is tested, but must not reintroduce wrong labels into evaluation. Source samples 15–20 / labels 616–620 have a prepared-only audit /tmp/ccc-face-source-label-exclusion-20261009.json; source remains unchanged pending exact source confirmation/authorization. No cleanup or ciphertext erasure.

Dataset: ControlFace10K CC BY 4.0 author revision a03589de1a9e028b2d16fa1eb0e019a6930e817c. 48 synthetic adult identities, 144 original PNG images, about 45.04 MB plus 2.3 MB metadata downloaded instead of 3.14 GB ZIP. HSFace reviewed (smallest LMDB 1.72 GB, MIT card, earlier identity-leakage correction); LFW deferred because commercial photograph rights were not established. No dataset imported into real/pilot enrollment/member tables.

Actual inference: all144 detected single faces, 138 quality-approved normalized128-dimensional vectors, six quality rejections retained as failures. Exact repeat cosine ~1.0, prepared model checksums and deployed contract verified. Model sface-int8bq-fb143eea; preprocessing yunet640-similarity5-rgb255-v1. Split24 development/24 held-out identities before inference; sixteen gallery/eight unknown identities per partition; correlated synthetic views are not independent live visits. Gallery one image; repeated query vector only meets the PHP three-observation shape, one trial counted per original image. Identification metrics condition on quality-approved images; no five-frame enrollment-accuracy claim.

A demonstrated defect was fixed in evaluation only: Python3.12 improved sum() differed from sequential PHP/JavaScript at an exact candidate score, flipping one duplicate decision. decision_evaluation.cosine now accumulates sequentially; calibrate_thresholds.pairs reuses that function. Actual106 identification/duplicate probes now agree with PHP. Numerical-boundary regression added.

Actual results: held-out64 genuine pairs mean0.58952,2214 impostor pairs mean0.22351; distributions overlap. Exploratory DEVELOPMENT-selected points duplicate0.5469521753 / attendance0.5667687778 are research-only and were NOT installed. Held-out 16-person gallery:18/30 known correct,12/30 unmarked,0/30 known wrong;1/22 unknown falsely accepted,21/22 unknown rejected,5/52 ambiguous safely unmarked. Duplicate point misses8/30 repeat queries and falsely rejects3/22 distinct queries. Results FAILED suitability for rollout; no validated pilot/production recommendation exists.

Tests executed and actual results:
- php tests/browser-face/exclusion.php:125 existing backend/lifecycle assertions plus12 new exclusion/scope/immutability/idempotency/rollback/activation assertions PASS; evidence /tmp/ccc-face-exclusion-test-20261009.txt. Initial new fixture run hit the existing deliberate test_rollback constraint; the temporary reused fixture explicitly drops it before its new scenarios. Final run passes.
- Python unittest discovery:14 tests PASS (existing6 plus new8).
- npm run test:face:23 JavaScript cases in3 Node test files PASS; authorization6 PASS; report-bound policy9 PASS.
- Existing pilot integration15 PASS including unchanged source fingerprint and local domain deductions; additive migration3 temporary-schema checks PASS.
- Existing manual/domain regressions18 PASS, unlimited browser regression PASS.
- Existing browser inference+camera suites rerun:4 PASS, real YuNet/SFace WASM with simulated camera/public fixtures; same-raster OpenCV reference cosine0.9999513689. Earlier full12 browser run remains historical; no new full12 run claimed.
- Actual new public inference144 images;106 PHP matching parity cases PASS; PHP/JS syntax and git diff --check PASS.

Changed/added files this task: classes/services/BrowserFaceEvidenceExclusion.php; scripts/browser_face_admin.php; evaluation/download_controlface_subset.py, public_dataset_inference.cjs, backend_match_probe.php, public_dataset_evaluate.py, test_public_dataset.py; evaluation/decision_evaluation.py and calibrate_thresholds.py; tests/browser-face/exclusion.php; docs/api/browser-face.md; docs/PUBLIC_FACE_DATASET_EVALUATION.md, IMPLEMENTATION_STATE.md, BROWSER_FACE_CALIBRATION_REPORT.md, BROWSER_FACE_FINAL_DELIVERY.md; tests/browser-face/README.md. No schema migration, service reload, production policy installation, new recognition model or frontend flow. Existing backup/recovery files and user's vim swap file preserved.

Private evidence: /tmp/ccc-controlface-subset-20261009/manifest.json; /tmp/ccc-controlface-inference-20261009.json; /tmp/ccc-controlface-evaluation-20261009.json and .md; /tmp/ccc-face-one-person-repeat-report-20261009.json. Reports/images outside web root, protected permissions; temporary decrypted pilot JSONL removed after evaluation.

Remaining human actions: keep all actual one-person captures under623; obtain later dated/lighting/device captures and record real recovery/browser-restart outcomes. Agent has no physical camera access. Pilot recognition and live duplicate/unknown/restart remain BLOCKED by unvalidated matching and missing distinct real people; no public/synthetic bypass. At least19 additional real consenting identities are needed to reach20, ≥100 representative captures overall,2calibration+2later validation dates per identity, correctly labeled unknowns with real nonempty rosters and child/similar-looking/device coverage. Counts are only preliminary targets. Confirm source mislabeled sample scope before authorizing application of its prepared reversible plan. Review independent physical/error results and authorize production rollout only after evidence qualifies.

Exact next actions: use current existing attendance.php?face_pilot=1 Practice screen / branch2 / batch33 / TestOne623. Pilot expires2026-10-16 18:10:59IST. Do not relabel the same person as624/625 to increase identity counts; genuinely different participants may later use those cleared identities. Do not install public operating points. See docs/PUBLIC_FACE_DATASET_EVALUATION.md for reproducible commands, observed metrics, rollback and next tests.

## Recognition failure investigation and product recovery — 2026-10-09 (latest)

Current phase: existing embedded product validation/necessary fixes, governed by PRODUCT_GOAL.md. Master and recoverable source baseline /tmp/ccc-recognition-failure-baseline-20261009 established before functional changes. Existing private database backups and user-owned docs/.BROWSER_FACE_FINAL_DELIVERY.md.swp preserved. No model, preprocessing, recognition threshold, AttendanceDomainService, schema, live biometric/attendance/membership record or production acceptance configuration changed. Disposable browser fixture databases were created and ownership-checked removal completed.

Reproduced existing synthetic benchmark from the actual saved worker inference, then reran all72 development images through the unchanged browser worker. Development vectors exactly reproduced. Frozen held-out results unchanged:18/30 known correct,12/30 unmarked,0/30 wrong-known acceptance,1/22 unknown acceptance,8/30 missed duplicates,3/22 false duplicate rejection. The12 known rejections are8 correct-top below threshold plus4 multi-candidate ambiguities; correct top ranks29/30. Development has5 correct-top rejections,4 known ambiguities,1 wrong-known acceptance,4 unknown acceptances. All four development unknown acceptances persist using reference embeddings. Similarity overlap, pose/synthetic identity consistency and discrimination remain unresolved recognition risks; no demonstrated browser preprocessing defect was found. Held-out images were not inspected or used for tuning. No accuracy improvement is claimed.

Extended existing evaluation/public_dataset_inference.cjs with private development-only raster/landmark/quality/crop diagnostics. Existing tests/browser-face/reference_encoder.py now optionally compares those rasters using the existing CPU encoder plus official OpenCV FaceRecognizerSF alignment/features and FaceDetectorYN; the original CLI remains supported.72 development inputs/70 usable: minimum browser/CPU cosine0.99994106, browser/official OpenCV0.99994072; maximum official alignment mean pixel error0.109322 on0–255; maximum landmark difference1.28705px at640. Inputs floatRGB1×3×112×112, outputs128 normalized; weights/contract unchanged. One exact development threshold-boundary decision changes in CPU reference because score0.5667580472 is about0.00001073 below browser0.5667687778; this is runtime interpolation/numerical sensitivity, not the earlier corrected Python/PHP summation defect. The existing106 PHP parity probes and numerical regression remain passing.

All14 development error-bearing images were visually checked with detected landmarks, actual aligned crops and top candidates; all pass quality. Examples001/002 have correct landmarks but profile-gallery genuine scores0.51616/0.51297; unknown060 and another-label gallery138 have very similar synthetic appearance and score0.78036. No crop/channel/landmark defect demonstrated. Private diagnostic sheets /tmp/ccc-recognition-all-development-failures-{1,2}-20261009.png retain inspection evidence. No held-out images inspected.

Extended existing public_dataset_evaluate.py with private per-query failure classification, ranking/scores/ambiguity and duplicate flags, plus one meaningful diagnostic regression. No vectors/images in ordinary logs. Existing thresholds/split/metrics remain exactly unchanged. Three actual five-observation captures under only trusted member623: stored/JS-contract/PHP aggregates cosine≈1; normalized mean and existing outlier aggregate agree with all5 retained. Aggregate has small mixed genuine effects across captures, no demonstrated algorithm defect. Development synthetic three-view aggregate max impostor score0.782296 vs first-view0.831331, but lacks an independent genuine query and does not validate camera aggregation. Original reference preserved; no extra frames or adaptation. Actual plaintext pilot export removed after scalar analysis.

Verified functional defect: losing all attendance responses, then closing camera, left Retry with no scan lease and an open stalled panel. Three failing regressions also reproduced old-session stalled panel and stale retry error overwriting newer state. Small pf/js/browser-face/embedded.mjs fix recovers the exact idempotent request and opens a fresh authorized scanner for the unchanged context; old-session recovery closes its panel and late completion/error updates are generation-guarded. Strengthened existing workflow.spec.cjs drops all3 responses after real domain commit, closes camera/lease, restores network and verifies fresh scan/camera/worker, same saved member/context and exactly1 attendance/event/deduction.

Actual tests after fixes: full12 browser scenarios PASS in3.1min using actual attendance.php/AngularJS, WASM YuNet/SFace, PHP/MySQL and AttendanceDomainService with simulated public-photo camera; no physical-camera claim. Reference helper original-path integration rerun1 PASS after extension. JS26cases/3files PASS, PHP backend/lifecycle/exclusion137assertions PASS, pilot15 PASS including unchanged source fingerprint, temporary migrations3 PASS, evaluator15 PASS, auth6 PASS, policy9 PASS, existing domain6/characterization9/regressions3/unlimited browser contract PASS. Development72 real-model diagnostics/OpenCV assertions PASS; actual benchmark106 PHP parity cases PASS; syntax/git diff checks PASS. Simulated enrollment4538.4ms, startup90.6ms; sustained detectionP50/P95320.7/349.5ms, recognition91.6/107.2ms. Physical latency unavailable.

Current read-only state supersedes prior pilot snapshot: source ccc_database fivePENDING/zeroACTIVE,20captures/six labels/zero independent validation; labels are not proof of distinct people. Real isolated pilot has6 calibration records: prior2/3 soft-excluded, trusted623 captures1/4/6 (15observations,one date), later624 capture5 is not independent identity evidence and excluded from this investigation. Prepared-only protected reversible review /tmp/ccc-face-pilot-later-label-review-20261009.json for sample5; no exclusion applied this turn. Pilot twoPENDING/oneRECAPTURE_REQUIRED,zeroACTIVE/attendance/events,matching_ready=false. Protected branch2/batch33/member623–625 isolation verified; expiry2026-10-16 18:10:59IST. No public settings installed.

Changed in this iteration: pf/js/browser-face/embedded.mjs; tests/browser-face/{embedded.test.mjs,workflow.spec.cjs,reference_encoder.py,README.md}; evaluation/{public_dataset_inference.cjs,public_dataset_evaluate.py,test_public_dataset.py}; docs/{api/browser-face.md,PUBLIC_FACE_DATASET_EVALUATION.md,RECOGNITION_FAILURE_INVESTIGATION.md,IMPLEMENTATION_STATE.md}. core/capture/worker/attendance bridge/contract and BrowserFaceService are byte-identical to this iteration's baseline.

Milestone: usable now—existing manual attendance plus integrated consented automatic capture/encrypted PENDING persistence. Improved—scanner recovers after camera closure/network uncertainty without duplicate attendance/deductions; administrator diagnostics now distinguish actual recognition failures. Live blocker—no validated matching configuration; public genuine/impostor overlap and absent independently labeled real-person validation. Next engineering action—inspect actual same-person repeated capture scores and quality across separate visits/lighting, tracing a real camera failure through the now-verified contract if observed. No demonstrated model/preprocessing change is justified. Safe-deployment evidence—physical known/unknown/duplicate/sequential/repeat/recovery tests, representative child/device/similar-looking coverage, independent matching validation and explicit production approval remain required. Count targets alone are not acceptance.

Status: executable product/model/domain/persistence/recovery checks VERIFIED within automated scope; public points FAILED suitability for rollout; physical camera/person/device trials and production attendance BLOCKED (no /dev/video* or user-camera tool); database/backend process restart NOT TESTED (fresh browser/service-object persistence verified). Exact operator steps and full failure evidence: RECOGNITION_FAILURE_INVESTIGATION.md. Operator should use Practice attendance.php?face_pilot=1, Testing branch2/batch33, TestOne623 for the available person's separate consented captures; do not manufacture another identity under624/625. Actual recognition trials require properly labeled different known/unknown people and an administrator-reviewed data-derived pilot-only report. Production requires unchanged independent release policy and explicit rollout authorization. Do not restart phases, lower thresholds for appearance, or present simulated tests as physical success.

## Post-pilot physical capture inspection and functional completion — 2026-10-09 (latest)

Current phase: inspect actual newly collected physical evidence, correct labels and verify the existing product; no restart/redesign. Read PRODUCT_GOAL.md, this state and RECOGNITION_FAILURE_INVESTIGATION.md before work. Master confirmed. Pre-change source baseline /tmp/ccc-face-postpilot-baseline-20261009 and fresh pilot-only backup /tmp/ccc-face-postpilot-before-20261009.sql protected; previous private backups and user's documentation swap preserved. No production biometric/attendance/membership/configuration changes, service restart or migration.

Read-only initial inspection found12 physical pilot captures/60 observations,6new IDs7–12/30 observations at22:10:09–22:10:41 under623/624/625. Operator explicitly confirms one actual person, not3. Every capture has5 original normalized128-dimensional observations, encrypted aggregate and matching sface-int8bq-fb143eea / yunet640-similarity5-rgb255-v1. All12 enrollment journals returnedPENDING; original623 sample1/captureafb412cc-2dca-4c2f-8eab-aad31daed697 and ciphertext unchanged. Two privacy-preserving camera/browser fingerprints, all measured-normal, one actual date/visit; no proof of2devices, distinct people or independent cross-day validation. Failed camera/frame attempts, raw images, landmark/blur/clipping/exposure/rotation metadata and physical latency were not retained; cannot claim0failures or physical alignment/quality correctness from their absence.

Applied user-requested existing reversible exclusion ONLY on verified ccc_database_face_pilot_10092026: samples5,8,9,10,12 under624/625 soft-excluded; evidenceLABEL_CONFLICT; candidateRECAPTURE_REQUIRED/LABEL_EXCLUDED. Existing2/3 exclusions remain. All ciphertext/history/member/contact/attendance preserved. Audit /tmp/ccc-face-postpilot-label-exclusion-20261009.json(.events.jsonl), exact fingerprints/guarded rollback. Retain623 samples1,4,6,7,11; corrected export5captures/25observations/1label/0validation. Wrong-label observations used solely as operator-confirmed same-person diagnostics, never as independent identities or impostor evidence. Source ccc_database26captures/six selected labels remains5PENDING/0ACTIVE/0validation and was not altered.

Actual same-person results:66 template pairs min0.7467827910/max0.9718720371/mean0.8432552742;1770 individual observation pairs min0.5723930587/max0.9874282210/mean0.8004519406. Cross-capture observation pairs1650 min0.5723930587/max0.9583122382/mean0.7922367450. New6templates versus original623 min0.7467827910/max0.8255019063/mean0.7933631542; new30observations versus original min0.7088417650/max0.8284043641/mean0.7695739023. Retained623templatepairs10 min0.7963090909/max0.9211729884/mean0.8345800227. Within earlier/later fingerprint group means0.887186/0.887517 vsbetween0.806508; actual condition-associated shift, not proven specific hardware/alignment cause. Exact lowest observation pair sample6index3 vs11index4. Actual JavaScript/PHP-contract aggregation reproduces all storedtemplates cosine≈1. Newbatch retainedcounts4,3,4,4,5,5;5embedding outliers discarded across4batches, still≥3 usable. No justified recognition model, preprocessing, aggregation or live threshold change.

Executed existing BrowserFaceService/AttendanceDomainService on actual stored physical observations in the verified pilot with a private CLI-only genuine replay configuration, frozen on earlier retainedcaptures1/4/6 before later queries:duplicate0.802045105977794 / attendance0.6513243536866755. Positive-only fit, zero impostor/unknown evidence; production_approved=false,pilot_installable=false, no installed live report changed. One compatible original623 template activated test-only,templateID1. Activation retry after a temporary-table setup failure reused journal key and produced no duplicate template. Original encrypted enrollment reference preserved. Duplicate enrollment submission using actual repeatcapture4 against624 rejected via actual lifecycle in connection-local temporary evidence/journal tables, preventing replay from being falsely stored as another physical capture.

All11 repeated historical batches mapped by operator-confirmed ground truth to623 recognized through the actual service; same-day/recorded replay, not11people or physical live scanner trials. AttendanceID1/eventID1/contact791/member623/branch2/batch33/session1/date2026-10-09, sourceFACE_MATCH/PUNCH_IN. Pilot unlimited membershipusage stays0; repeated arrivals/exact keys/newbackend/newscanner add no records/events/deductions. Closed scanner plus injected lost journal response recovered committed event. Wrong scheduled time rejected. Original template ID/ciphertext/calibration/capture-session association unchanged. Actual backend responses replayed through the existing Angular bridge update only791, leave792/793unmarked, ignore changed session and add no duplicate usage. Source attendance/event/test membership/template/enrollment fingerprints identical before/after.

Recognition limitation found: the frozen duplicate replay point misses newer same-person templates9/10/12 (3/11repeatqueries;3/6newcaptures). This was measured, not hidden by lowering the cutoff. Backend cosine/aggregation agree; no proven comparison defect. No false-accept/unknown error rate can be estimated from one person. Private replay audit /tmp/ccc-face-postpilot-backend-replay-20261009.json and intent /tmp/ccc-face-postpilot-replay-intent-20261009.json explicitly deny installation/production approval. Actual scalar report /tmp/ccc-face-postpilot-consistency-20261009.json; row-contract /tmp/ccc-face-postpilot-row-contract-20261009.json. Both temporary decrypted exports removed; encrypted data/backups retained.

Independent demonstrated functional fix: an old worker's queued onerror lacked the epoch/active guard and could stop a restarted capture or clear a replacement capture's shared video.srcObject. Regression failed before correction; additional isolated baseline probe reproduced cleared replacement preview. One-line pf/js/browser-face/capture.mjs guard now ignores old errors, while current errors stop tracks/worker and report retry. Tests cover same instance and replacement instance around the same preview. No ordinary UI flow changed.

Actual final tests: JavaScript27cases/3files PASS; full12 existing real-model PHP/MySQL/Angular browser scenarios PASS in3.0min with simulated/public-photo camera (capture4502.9ms; not physical timing). Real WASM/OpenCV reference cosine0.9999513689; continuous two-person scan, camera cancellation/retry, lost committed response, row updates, fresh browser session persistence and cleanup PASS within automated scope. Backend/lifecycle/exclusion137assertions PASS; pilot15 PASS including source fingerprint; migration3temporary-schema checks PASS; evaluator15 PASS; auth6 PASS; policy9 PASS; manual/domain attendance6+9+3 and unlimited-browser contract PASS. Ownership-checked disposable browser databases removed; consenting-person pilot preserved. Syntax/diff checks PASS. Evidence /tmp/ccc-face-postpilot-{unit-results,backend-tests,browser-tests}-20261009.txt and stale-worker-before test result.

Final actual pilot state:1ACTIVE623 (originalsample1),2RECAPTURE_REQUIRED wrong labels,12original physical captures with7softexclusions,1test attendance/1event, installedmatching_ready=false. Source0ACTIVE and acceptance_ready=false unchanged. Exact branch2/batch33/member623–625 local schema/view/owner guard remains verified; expiry2026-10-16 18:10:59IST. Existing production manual attendance remains usable. Reopening Practice attendance can show the saved test enrollment/attendance; camera Scan Arrivals still cannot perform new identity decisions under the disabled installed policy.

Statuses: VERIFIED WITH PHYSICAL CAMERA—operator-confirmed capture/observation/template generation/encrypted persistence, original reference survival across repeated captures. VERIFIED WITH AUTOMATED TESTS—actual stored-physical-observation backend replay and frontend contract, activation, conditional duplicate submission, domain attendance uniqueness/recovery/session/context, worker recovery fix and existing full regression suite. FAILED—genuine-only replay duplicate point misses3new templates. BLOCKED—live pilot/production biometric acceptance due no independently labeled different/unknown people and no independent validation; no installed threshold/gate override. NOT TESTED—physical live recognition after browser restart, continuous new-camera attendance/network recovery, unknown/different-person rejection, actual desktop/Android/iPhone identification/rotation/timing, database/backend process restart. Do not claim stored replay proves live physical recognition.

Milestone: usable now—ordinary manual attendance, integrated automatic consented capture and encrypted persistent identity/evidence; Practice has real test reference/attendance saved by controlled replay. Improved—incorrect labels are excluded, actual capture consistency measured, original reference activated only test-side, full backend replay completed, camera restart race fixed. Live blocker—one-person positive-only fit cannot validate unknown rejection; its observed duplicate misses also preclude claiming an acceptable matching configuration. Engineering next action—trace any subsequent actual cross-condition failures with existing camera quality/alignment diagnostics; do not invent a pixel cause, adapt the reference or lower thresholds. Evidence required—correctly labeled different-person/unknown comparisons, independent real camera recognition and recovery; representative production validation and explicit approval remain the unchanged release requirement. No request for another large dataset was made before inspecting current data.

Exact next operator steps: reopen existing /pf/attendance.php?face_pilot=1 in Testing branch2/batch33; retained TestOne623 should show Face enrolled and its test attendance. Keep the available person under623; do not recreate false identities under624/625. New live Scan Arrivals remains disabled until the existing evaluator can produce a reviewed pilot-only separation report from genuinely distinct consenting people; existing pilot policy needs two real identities with repeats, not merely two labels. Physical unknown trials still need an actual unenrolled person. Source ordinary attendance remains fully manual-operational; production automatic attendance requires independent approved evidence and rollout authorization. Current captures have all been examined; no repeat collection is needed to finish this inspection. Details/timestamps/score tables/statuses are appended to RECOGNITION_FAILURE_INVESTIGATION.md.

Files changed this continuation: pf/js/browser-face/capture.mjs; tests/browser-face/core.test.mjs; docs/api/browser-face.md; docs/RECOGNITION_FAILURE_INVESTIGATION.md; docs/IMPLEMENTATION_STATE.md; tests/browser-face/README.md. Private scoped inspection/replay scripts and audit files stay outside the web root. Backend, models, contract, database schema, authentication, manual attendance and ordinary enrollment/scanning layout are preserved.

## Enabled live-camera pilot — October 10 delivery, executed October 9 evening IST (CURRENT)

Operational improvement: the existing Practice `attendance.php?face_pilot=1` scanner now has installed matching readiness, including in the actual serving PHP8.3-FPM process. The camera/worker/API/domain/row-update flow can run with a fresh observation. Fresh physical attendance remains NOT TESTED; no camera is available to the agent. Production matching remains disabled. This section supersedes the earlier two-person requirement as a blocker for the explicitly authorized single-person functional demo only; production and the ordinary multi-identity pilot requirements are unchanged.

Root cause: BrowserFacePilot::policy required at least two real identities and genuine/impostor trials. The actual dataset has one person, so backend config returned acceptance_ready=false and EmbeddedFaceFlow.open returned before opening the camera. Added an explicit protected-report SINGLE_IDENTITY_LIVE_DEMO mode, not a browser readiness override. Existing Attendance permission/branch checks, private file/hash/expiry, exact database owner/population, local views, no foreign-schema triggers/keys, branch2/batch33 and attendance allowlists remain enforced. The original ACTIVE623 reference is pinned by template/enrollment/calibration/capture association and two ciphertext hashes. Changed reference or any additional ACTIVE template fails closed. New candidates may be genuinely duplicate-rejected, but cannot activate in this mode. No recognition result is hardcoded; existing per-observation cosine/unanimity matching selects or rejects the identity. No schema, model, preprocessing, attendance service or production policy changes.

Installed report `/tmp/ccc-face-live-pilot-report-20261010.json`, protected0640/root:www-data, bound by SHA in the existing `/tmp/ccc-face-pilot-20261009.json`. Expiry unchanged: **2026-10-16 18:10:59 IST**. Original templateID1/enrollmentID1/calibrationID1/member623/captureafb412cc-2dca-4c2f-8eab-aad31daed697 remains unchanged. Pilot database ccc_database_face_pilot_10092026; zero-valued test memberships only. New report explicitly functional_demo_only=true, production_approved=false, recommendations=null. Production approval validation rejects promotion of this report.

Evidence-derived provisional settings: ATTENDANCE_THRESHOLD0.6513243536866755 preserves the earlier point frozen on captures1/4/6; DUPLICATE_THRESHOLD0.746782791039185 is the minimum actual repeat-template score to the unchanged original. The earlier duplicate point0.802045105977794 misses3/11 repeats; the fitted lower envelope misses0/11 on the same data, **not independent accuracy improvement**. Frozen attendance matches11/11 historical batches; fresh camera unknown rejection is unmeasured. All12 batches/60 observations are one operator-confirmed person, one sitting/date, zero impostor/unknown/independent validation trials. Wrong-label samples remain reversibly excluded and were used only as same-person diagnostic comparisons. No production thresholds lowered. Existing original observations score0.992–0.994 to their aggregate, so no demonstrated original within-batch failure. Offline medoid/aggregate reference minima improve on these fitted captures, but original reference was not replaced and no adaptation introduced. Detailed scalar comparison `/tmp/ccc-face-live-pilot-reference-comparison-20261010.json`.

Diagnostics: pilot panel shows bounded camera start/dimensions, engine readiness, sampled frames, face count, exposure/blur/clipping rejection reason, inference timing, submitted observations, matching decision, request key, saved context/attendance ID. Backend filters optional numeric capture diagnostics and stores safe matching/context scalars in the existing request journal. Client camera metrics are untrusted reports, not proof of physical capture. No images/landmarks/embeddings/ciphertexts/scan tokens displayed or logged. Ordinary production responses omit diagnostics. A demonstrated new layout issue pushed expanded diagnostic controls beyond720px viewport (dialogtop−172); bounded scrolling dialog fixes it (top20,bottom700). Existing worker/stale-session/network recovery protections preserved.

Tests actually executed:
- JavaScript29 registered cases/3Node files PASS; two new diagnostic sanitization/persistence regressions first failed before implementation.
- Pilot34 assertions PASS, including readiness, report/model/expiry/reference/population tampering, unapproved activation, duplicate rejection, below-cutoff/ambiguous/conflicting rejection, safe diagnostics, actual domain uniqueness/deductions and unchanged source fingerprint. Initial readiness regression failed before implementation; expanded-population test initially had an prematurely removed temporary report, corrected before final pass.
- Existing backend/lifecycle/exclusion137 assertions PASS; auth6, production policy10 (new demo-promotion rejection), temporary migrations3, evaluator15, manual/domain attendance6+9+3 and unlimited browser contract PASS.
- Full12 browser scenarios PASS in3.0min using real YuNet/SFace WASM, actual attendance.php/Angular, PHP/MySQL/domain with **simulated public-photo camera**. The attendance-page scenario switches to the real protected single-person report and normal controller/service, without constructor-injected readiness/results, then verifies duplicate, scanning, actual attendance/event, safe diagnostics and new-browser persistence. Final actual-page/layout rerun after the CSS fix:1PASS in25.1seconds (`final-page-test` evidence). Owned disposable fixtures and scoped grants removed again; real pilot preserved.
- Existing106 frozen public numerical decision probes remain equal; synthetic held-out18/30 correct,12/30 rejected,0wrong-known,1/22 unknown acceptance and8/30 missed duplicates unchanged. No new model research/tuning or held-out optimization.
- Serving PHP-FPM private read-only probe VERIFIED pilot_ready=true, production_ready=false, original_reference_verified=true, opcache_timestamp_validation=1. Live JS asset HTTP200/application/javascript. Syntax/diff checks PASS. No public diagnostic endpoint, service restart, source write or production rollout.

Actual final records before human testing: pilot12 captures/7 retained soft exclusions/one ACTIVE623/two RECAPTURE_REQUIRED labels/one attendance+event from the prior stored replay; local membership usage0. Source26calibration records/six labels (not six confirmed people)/fivePENDING/zeroACTIVE/zero validation; source attendance/event/membership/template/enrollment fingerprint unchanged. Test date2026-10-10 has authorized sessionID2/start09:00/end21:00 and an unmarked623 row; date2026-10-09/sessionID1 is already marked by replay. No new real-pilot attendance was manufactured this iteration.

Recovery baseline `/tmp/ccc-face-live-pilot-baseline-20261010`, fresh encrypted pilot backup `/tmp/ccc-face-live-pilot-before-20261010.sql`600; prior backups and user's documentation swap preserved. Restoring only the baseline protected pilot configuration disables live matching without deleting captures/templates/history. Existing owned disposable browser databases and grants are removed after tests; consenting-person pilot remains. Temporary decrypted physical export is removed after scalar analysis.

Changed executable files: classes/services/{BrowserFacePilot,BrowserFaceService}.php; controllers/browserFace.php; scripts/browser_face_pilot.php; pf/js/browser-face/{core,worker,capture,embedded}.mjs; pf/inc/browser-face-panel.php; pf/css/browser-face-embedded.css. Tests changed: pilot.php,policy.php,embedded.test.mjs,attendance-page.spec.cjs,router.php,remove-pilot-fixture.php. Contract/evidence docs updated; no unrelated rewrites. Main recognition decisions and attendance business rules remain unchanged.

Critical acceptance, separating fresh physical proof from automated evidence:

| # | Criterion | Status / actual evidence |
|---|---|---|
|1|Camera opens inside attendance.php|VERIFIED automated protected-policy browser flow; fresh physical scanner NOT TESTED|
|2|Existing enrolled identity persists|VERIFIED original encrypted623 reference/pin read back, retained through repeated captures; new-browser fixture persistence|
|3|Fresh physical camera generates embeddings|NOT TESTED this scanner; prior12 physical enrollment batches verified, actual-model virtual-camera tests pass|
|4|Fresh physical observation reaches backend|NOT TESTED; live route/config enabled, automated protected-policy request passes|
|5|Genuine backend identity matching|VERIFIED actual cosine/unanimity code and model fixture; fresh physical decision NOT TESTED|
|6|Accepted decision creates isolated domain attendance|VERIFIED automated actual domain record/event/deduction; fresh physical write NOT TESTED|
|7|Correct row updates immediately|VERIFIED actual Angular page automated; fresh physical update NOT TESTED|
|8|Repeat attendance prevented|VERIFIED automated database/event/deduction/idempotency and earlier recorded-physical replay; fresh physical repeat NOT TESTED|
|9|Scanner stays open/rearms|VERIFIED real-model automated continuous/two-person tests; fresh physical continuous scan NOT TESTED|
|10|Camera/browser/network/session recovery|VERIFIED automated cleanup/retry/lost response/stale generation/context and modal bounds; physical recovery NOT TESTED|
|11|Production data/gate untouched|VERIFIED source fingerprints, isolated owner/views/population and serving FPM gatefalse|
|12|Existing regressions pass|VERIFIED suites/results above|

Exact human test: sign in with existing Attendance permission in Testing branch2, hard-refresh https://admin.creativeculturalcentre.in/pf/attendance.php?face_pilot=1 and confirm Practice attendance. Select batch33 (Face Camera Test 2026-10-09), **10-10-2026**,09:00–21:00/session2. One/623 should already show Face enrolled; do not re-enroll before scanning. Click Scan Arrivals, allow camera, present the same consenting person naturally. Expect Attendance marked, row Already recorded, camera remains open. Expand Practice camera diagnostics: engine_ready=true, face_count1, observation_count5, decisionMATCHED, backend_stateRECORDED, person623/session2 and nonzero attendance_id. Step away/return, then close/reopen/reload: expect Already marked and one record/event. Restore connectivity then Retry after interruption; changing session must close the old scanner. If it fails, report the visible error plus stage/quality_reason/decision/request_key; no images/vectors needed. A duplicate-only attempt against another test row may be tested but must not be counted as a different person.

Milestone: usable now—ordinary manual attendance unchanged; persistent enrolled623 plus enabled isolated live scanner, genuine matching, continuous domain writes and row updates ready for one-person demo. Product improvement—removed the precise readiness blocker safely, added actionable privacy-preserving diagnostics and fixed modal reachability. Remaining pilot engineering blocker—none demonstrated after executable tests; fresh physical compatibility/recognition outcome is still untested and may expose a new defect. Next action—perform that short real-camera test and inspect its recorded request/decision/persistence diagnostics if it fails. Safe production deployment remains BLOCKED by absent independent different/unknown/representative child/device/session validation and production approval. A one-person positive-only demo does not establish biometric accuracy or authorize real attendance.

Private evidence: `/tmp/ccc-face-live-pilot-{browser-tests,final-page-test,node-tests,backend-tests,guard-tests,attendance-tests,production-policy-tests,evaluation-tests,parity-results}-20261010.txt`; installed/readiness/final-state/reference-comparison JSON; fpm-result and layout-before/after. Physical capture time remains unavailable; measured4571.6ms enrollment, camera90ms, detectionP50/P95318.6/328ms and inference92/120.2ms apply only to this headless simulated-camera run.

## Android live verification and duplicate fix — October 10, completed

Read PRODUCT_GOAL.md, this state and RECOGNITION_FAILURE_INVESTIGATION.md; master confirmed. Recovery baseline `/tmp/ccc-face-android-baseline-20261010`; fresh consenting-person pilot backup `/tmp/ccc-face-android-before-20261010.sql` mode0600. Existing migrations/backups/user-owned documentation swap preserved. No schema/model/threshold/AttendanceDomainService/authentication/production-policy change.

**VERIFIED WITH PHYSICAL CAMERA:** operator-confirmed Android TestOne623 recognition at10:54:09IST. Request `98877d35-ad7d-42a6-a1f7-679b0c31315e`, scan5, attendance2/event2, contact791/member623, branch2/batch33/date2026-10-10/session2; FACE_MATCH/PUNCH_IN. Five fresh observations scored0.796725–0.816558. Actual client metadata:480×640, startup474.2ms, detection193.7ms, inference78.9ms, zero reported quality rejections on that request. Enrollment completion time was not measured. Operator reports correct immediate row update; server response/context confirms its association but no physical DOM recording exists. Scanner closed10:54:11 and reopened10:54:14; request `4bc3ed94-7304-47df-81e4-6857cfafd0e7` at10:54:18 returnedALREADY_MARKED, same record/event and zero deduction. One attendance/event on October10/session2, two total including the earlier October9 replay. Local memberships1188–1190 remain sessions_used0.

Read-only audit found12 new physical batches13–24/60 observations/one real identity. TestTwo first morning request `7f561129-1af4-4162-b4da-04c792e4d003` at10:54:03 returnedPENDING despite current sample17 score0.804029; next `d05fa432-08c4-46dc-a07c-cc8d6c3b1eaa` score0.854160 likewise. TestThree request `03e027c4-47c9-425f-b806-213fbaf96073` at10:54:34/sample19 score0.827157 returnedREJECTED_DUPLICATE. All versions compatible; sole active candidate623 included. Unchanged pilot duplicate cutoff0.746782791039185. Exact cause: original pending624 sample13 (00:27:27, score0.583846) was correctly preserved, but every new attempt was incorrectly decided through activation of that older reference.625's rejected state allowed each new aggregate to be checked.624 neverACTIVE; its old decision wasPILOT_NOT_APPROVED, not a successful enrollment.

**Implemented:** BrowserFaceService now checks each submitted aggregate against all other compatible active templates before activation, preserving original PENDING/ACTIVE references. Attendance's identity allowlist cannot narrow the central duplicate search. Fresh rejected observations areLABEL_CONFLICT; a rejected attempt does not erase a previously valid selected-member reference. Angular uses the actual enrollment_state for row flags. Pilot-only bounded diagnostics bind comparison/input/sample/capture/request IDs and scores without vectors/images. Existing idempotency and tenant transaction lock remain authoritative. No forced movement, manual shutter, extra page or ordinary operator step introduced.

Actual encrypted-table temporary regression reproduces the8 morning batches17–24: **before5 PENDING/3 duplicate; after8 duplicate** against623. Current best scores0.779826–0.854160. Persistent records, original pending/active references and sole ACTIVE population verified unchanged. This replay is automated evidence, not a new physical-camera result. Added9 deterministic backend assertions plus1 Angular row-state test and real-model attendance.php duplicate-diagnostic assertions; the original regression failed before the fix.

**Preservation/exclusions:** existing reversible mechanism applied only to pilot samples13,14,16,17,18,19,20,21,22,23,24; repeated application Already excluded. Audit `/tmp/ccc-face-android-label-exclusion-20261010.json` and append-only `.events.jsonl` contain encrypted snapshots for guarded rollback. Eighteen total soft exclusions, six retained623 captures/30 observations/one identity/zero independent release-validation batches.624/625 nowRECAPTURE_REQUIRED/LABEL_EXCLUDED; ciphertext/history/journals remain. Original623calibration1/template1/enrollment1 unchanged. Attendance/events/membership rows unchanged by this iteration. Production fingerprint before/after `dbdff729bd1f5d64b14b6b7bbeae178fa80880533cbe388f81d6f926d997a99d`. Temporary decrypted export removed. Rollback of this audit requires unchanged snapshots and explicit approval to restore wrong labels; never use restored labels as separate people.

**All relevant executed regressions passed:** full12 actual YuNet/SFace/WASM/PHP/MySQL/AttendanceDomainService browser scenarios in3.2min, with simulated/public-photo camera;30 JS cases;146 backend/lifecycle/exclusion assertions;34 pilot guards/domain checks;6auth;10production-policy;3temporary-schema migration checks;15evaluation tests; existing manual/domain6+9+3 scenarios and unlimited-browser contract;106 PHP numerical matching probes. Real browser/OpenCV reference cosine0.9999513689 unchanged. Current simulated enrollment4816.8ms is not phone timing. Syntax and git diff checks pass. Both owned disposable browser fixtures removed through ownership checks; the real consenting-person pilot remains intact.

Serving PHP8.3-FPM private read-only probe VERIFIED current duplicate-search signature loaded, pilot_ready=true, production_ready=false, original reference verified, timestamp validation1. Probe removed afterward; no public endpoint/service restart/production deployment. Existing pilot expiry remains2026-10-16 18:10:59IST. Matching report/cutoffs unchanged; only623 can be recognized/active, new test identities cannot activate. No live attendance was manufactured by the agent.

| Critical result | Classification | Evidence / boundary |
|---|---|---|
|Android fresh face →623 →domain attendance|VERIFIED WITH PHYSICAL CAMERA|Operator confirmation; request98877..., attendance/event2, exact session/context|
|Phone scanner close/reopen and repeated attendance|VERIFIED WITH PHYSICAL CAMERA|Open/close journal; fresh request4bc3..., ALREADY_MARKED; one event/no deduction|
|Original encrypted enrollment persistence|VERIFIED|Original reference/pin unchanged; actual phone recognition uses saved template|
|TestTwo/TestThree duplicate root cause|VERIFIED|Actual submitted scores, retained old reference, original journal states, before/after replay|
|Fresh-capture duplicate fix|VERIFIED WITH AUTOMATED TESTS|Eight recorded Android batches now rejected; backend, Angular and real-model browser regressions|
|Post-fix fresh phone duplicate attempt|NOT TESTED|Requires one new capture; historical idempotency responses intentionally remain immutable|
|Uninterrupted rearming, next distinct arrival, refresh, network/permission recovery and stale responses|VERIFIED WITH AUTOMATED TESTS|Full browser/core/bridge suites; these physical variants remain NOT TESTED|
|Dark-light known-person matching|FAILED for recorded captures|13/14/16 templates below duplicate cutoff; five midnight attendance requestsUNKNOWN, no writes; exact optical cause unrecorded|
|Production automatic attendance|BLOCKED|One real identity; no independent different/unknown-person child/roster/condition validation or approved rollout|
|Production attendance/memberships/biometrics/gate preserved|VERIFIED|Identical fingerprints, isolated scope/pinned reference and serving FPM gatefalse|

Usable now: existing manual production attendance and the integrated isolated pilot's persisted623 enrollment, Android recognition/attendance/repeats. Improvement: current captures are genuinely duplicate-checked even when an earlier pending reference exists; UI remains truthful and failed-label evidence quarantined. Pilot has no remaining known code blocker for the confirmed normal-light path. Production blockers are representative independent validation and the observed dark-condition rejection, not a need to rebuild the pipeline. Next engineering action is to inspect safe quality/matching diagnostics if a fresh physical failure occurs, without threshold changes or template adaptation.

Exact immediate user check: reopen/refresh the existing `pf/attendance.php?face_pilot=1` in Testingbranch2/batch33; in normal light click Enroll Face on TestTwo and use the same consenting face. Expect “This face belongs to another member”; neither624 nor625 may becomeACTIVE. Then Scan Arrivals in the existing selected session, expect Already marked for623; keep the panel open, leave the camera view, return, close/reopen and optionally refresh to confirm those physical recovery variants. No re-enrollment of623 needed. Future production rollout requires correctly labeled independent consenting different/unknown-person/child/similar-looking/roster/lighting/device evidence, review of release error budgets and explicit authorization; do not count same-face member labels as different people.

Changed this iteration: classes/services/BrowserFaceService.php; pf/js/browser-face/{attendance,embedded}.mjs; tests/browser-face/{lifecycle.php,embedded.test.mjs,attendance-page.spec.cjs,README.md}; docs/{api/browser-face.md,PRODUCT_GOAL.md,RECOGNITION_FAILURE_INVESTIGATION.md,IMPLEMENTATION_STATE.md}. No unrelated source changes.

Private scalar evidence: `/tmp/ccc-face-android-{inspection,real-regression,exclusion-result,fpm-result}-20261010.json`; test logs `/tmp/ccc-face-android-{backend,node,browser,pilot,auth,policy,migration,domain,characterization,evaluation,unlimited,parity}-tests-20261010.txt` and `/tmp/ccc-face-android-attendance-regressions-20261010.txt`. Biometric images/vectors are absent from ordinary application logs; retained records/audits contain encrypted biometrics only.

## Targeted rear-camera and circular-guide UX — October 10

Implemented on master; recovery baseline `/tmp/ccc-face-camera-ux-baseline-20261010`. Previous backups/migrations/user-owned swap preserved. No backend, database, model, identity-threshold or original-template change.

Rear preference uses ideal environment constraints with ordinary-camera fallback. Actual track settings determine mirroring (confirmed front only), selected camera and retries; unknown facing is never guessed. Switch camera appears for multiple devices/dual-facing capabilities, releases the old tracks/worker, resets partial observations and preserves attendance context. Processing/pending requests block switching; stale callbacks cannot reopen closed cameras. Muted/playsInline are explicit. Refresh resets the rear preference.

One subtle circular overlay replaces the normal green rectangle, respecting letterboxing/rotation and dynamic viewport sizing. Diagnostic rectangles require explicit config.diagnostics=true. Full-frame YuNet and competing-face detection remain; no crop/mask/ROI optimization/new acceptance gate. Bounding boxes and eye/nose/mouth landmarks provide advisory Center face/Move slightly back feedback. Existing minimum resolution, exposure/blur, track continuity, five-observation collection and SFace preprocessing/aggregation remain unchanged.

References: official [ISO/IEC29794-5:2025](https://www.iso.org/standard/81005.html) and [ISO/IEC39794-5:2019](https://www.iso.org/standard/72156.html) summaries. No prescribed-circle or ISO certification claim; circle geometry is not a biometric threshold, and landmark bounds do not prove absence of occlusion.

VERIFIED WITH AUTOMATED TESTS:13 browser scenarios PASS in3.3min with real YuNet/SFace/WASM/PHP/MySQL/domain service and simulated video;44 individually executed JS cases,146 backend assertions,34pilot,6auth,10policy,3temporary-schema,15evaluation,18manual/domain scenarios, unlimited-browser contract and106PHP parity probes PASS. Reference cosine0.9999513689 unchanged; syntax/diff checks PASS. Portrait/landscape controls, circle/no box, switching/reset/cleanup, enrollment/duplicates, restart/retry and uninterrupted attendance passed. Initial switching harness failed on launch-option placement and Chromium's single file-camera limitation; final fixture simulates device enumeration/settings only, retaining real video/model inference. Physical hardware switching is not claimed. Owned disposable fixtures removed.

Same headless/public-photo harness, previous→current single runs: startup95.7→93.7ms; detectionP50/P95324.2/353.7→322.2/336.4ms; inferenceP50/P9592.8/106.5→92.3/104.3ms; enrollment4816.8→4728.6ms; reported JS heap10MB both; rendererRSS282728→281944KiB (shared pages included). Uncontrolled comparisons; no performance gain or phone-smoothness claim.

VERIFIED: before/after pilot and production fingerprints identical; only623ACTIVE, original ciphertext/reference, two existing attendance/events and zero local usage unchanged. Report/cutoffs unchanged; pilot_ready=true/production_ready=false. Previous Android attendance/close-reopen/repeat remains physically VERIFIED.

Physical UX tests NOT TESTED on Android/iPhone/desktop: refresh existing pilot attendance.php, Testingbranch2/batch33; TestTwo Enroll Face → confirm rear/circle/no box → switch before capture finishes → same consenting623 face should be duplicate. Scan Arrivals →623 marked/already marked → leave/return with camera open → close/reopen/refresh. Repeat on iPhone if available; desktop single-camera fallback should omit Switch. Production remains BLOCKED by representative independent different/unknown-person validation and authorization; recorded dark-condition rejection remains unresolved. No ordinary operator setup added. Next engineering action: inspect safe camera_facing/preview_mirrored/quality/matching diagnostics if a physical failure occurs.

Changed: pf/js/browser-face/{capture,core,worker,embedded,attendance}.mjs; pf/inc/browser-face-panel.php; pf/css/{browser-face-embedded,browser-face}.css; tests/browser-face/{camera.test.mjs,camera-switch.spec.cjs,embedded.test.mjs,attendance-page.spec.cjs}; docs/IMPLEMENTATION_STATE.md.

Evidence: `/tmp/ccc-face-camera-ux-{before,after,performance}-20261010.json` and camera-ux browser/backend/pilot/auth/policy/migration/evaluation/manual/unit logs. No biometric vectors/images exported or logged.

## Circular blackout and operator UX — October 10, completed

Phase5 targeted refinement on master. Existing Android physical attendance remains VERIFIED; updated physical-device UX is NOT TESTED. Recovery baseline `/tmp/ccc-face-circle-baseline-20261010`; previous backups/migrations/user-owned swap preserved.

Implemented an opaque black canvas overlay with a transparent circular center and subtle border. Shared captureGuide maps actual source/viewport dimensions, contain/cover cropping and confirmed front mirroring to the worker; ResizeObserver and per-frame geometry keys invalidate stale layout observations. Full-frame YuNet remains unmasked. Before SFace, selection checks detected face bounds, five landmarks and the inverse-mapped aligned rectangular crop against the same source circle. Outside/cut-off faces produce no embedding; competing faces pause collection. Guide exits discard partial observations. No ROI, new model, preprocessing/aggregation/identity-threshold change or template adaptation. Existing80px minimum, quality checks and five-observation contract remain. Landmark visibility does not establish validated occlusion/liveness defenses or ISO certification.

Normal UI now has Enroll Face/member and Scan Arrivals/class/date/time context, short instructions/status/errors, readable44px controls, safe-area spacing and accessible announcements. ACTIVE alone displays Face enrolled; pending displays Face captured. Setup is not ready for attendance yet. Genuine enrollment releases resources and closes after brief feedback. Attendance displays the authorized name briefly, keeps detection running and suppresses further inference/submissions for the marked track until departure, competing faces, discontinuity or guide/layout change rearms it. A seamless identical-position image replacement without track loss does not signal departure; normal sequential arrival tests include an actual no-face interval. No similarity-based browser identity guessing. Camera switching/rear preference/cleanup remain. Independent Check face tools and diagnostic JSON are hidden behind explicit authorized pilot Practice controls. Normal mode has no green rectangle or landmarks.

VERIFIED AUTOMATED:14 complete browser scenarios PASS in3.5min using actual YuNet/SFace/WASM/PHP/MySQL/domain service with public-photo simulated cameras; final attendance.php recheck PASS in30.7s (brief feedback, zero further same-track requests, blackout pixels in portrait/landscape/desktop, fresh browser persistence).52 individually executed JS cases PASS;146 backend/lifecycle/exclusion assertions,34pilot,6authorization,10production-policy,3temporary-schema,15evaluation tests,106PHP matching parity probes,18existing manual/domain scenarios and unlimited-browser contract PASS. Syntax/diff checks PASS. Actual-model outside/competing/cropped scenes have null embeddings; accepted gated vs unrestricted same-image embedding cosine1.0. Browser/OpenCV reference cosine0.9999513689 unchanged.

Initial failures: old test photo extended outside the required circle; it was reframed using the existing detector/alignment footprint. Continuous fixture's instantaneous same-position swaps did not exercise departure; added no-face intervals and same-track suppression assertions. Disk-full pilot/schema tests were rerun successfully after shrinking regenerable public videos; an empty failed disposable schema was removed after exact namespace/table/zero-row checks. No human captures/backups deleted. Final owned disposable fixtures/videos removed.

Measured current simulated run: startup88.7ms; enrollment4528.9ms; sustained detectionP50/P95311.5/318.2ms, inference92.8/105.3ms; reported JS heap10MB, rendererRSS288044KiB including shared pages. Previous run4728.6ms used different public-video framing; no controlled speedup, memory improvement or phone-performance claim. Full-frame processing preserved.

VERIFIED unchanged source/pilot fingerprints; original623 remains the only ACTIVE identity, two attendance/events and local deductions unchanged, pilot_ready=true/production_ready=false, matching report unchanged. All six HTTPS camera assets match source; entry/module/worker/CSS cache versions updated. No production identity activation/data write/deployment setting change.

Changed source: pf/attendance.php; pf/js/controllers/batchAttendanceController.js; pf/inc/browser-face-panel.php; pf/css/browser-face-embedded.css; pf/js/browser-face/{core,capture,worker,embedded,attendance}.mjs. Tests: tests/browser-face/{core.test.mjs,camera.test.mjs,embedded.test.mjs,guide.spec.cjs,workflow.spec.cjs,attendance-page.spec.cjs,z-continuous.spec.cjs,a-pending.spec.cjs,inference.html,prepare_public_fixture.py,README.md}. This state file updated.

Usable now: existing manual attendance plus updated integrated isolated pilot enrollment/duplicate/scanning UI; previous physical623 recognition persists. BLOCKED production: representative independent different/unknown/child/device/lighting validation and authorization; known dark-condition rejection unresolved. Server storage remains extremely low (~220MB free); capacity cleanup beyond disposable task output is required for sustained use. Updated Android/iPhone physical mask/switch/positioning/continuity acceptance remains NOT TESTED by the agent.

Exact next action: hard-refresh attendance.php?face_pilot=1, Testingbranch2/batch33/current authorized date/session. TestTwo Enroll Face: rear camera, clear circle/black surroundings, no green box; place the same consenting623 face within it, expect Face already registered. Switch before collection completes. Scan Arrivals: expect623 marked/already marked, row updates, camera remains; move completely outside the circle and return, close/reopen/refresh. Confirm outside face produces no capture and two faces pause. Repeat in portrait/landscape, Android Chrome/iPhone Safari if available; desktop single webcam need not offer Switch. If failure occurs, expand Practice camera diagnostics and report safe status/stage/quality/decision/request context only. Do not use these same-face labels as independent calibration identities.

Evidence: `/tmp/ccc-face-circle-{before,after,served-assets}-20261010.json`; browser-final/final-page/unit/backend/pilot/auth/policy/migration/domain/characterization/attendance/evaluation/parity logs. No biometric vectors/images in ordinary application logs.

## Camera stability and clear operator responses — October10 (completed executable verification)

Phase5 focused corrections on master; baseline `/tmp/ccc-face-stability-baseline-20261010`. Existing physical Android623 recognition/attendance evidence, original encrypted reference, exclusions, migration/backup history and production matching gate preserved. No backend/model/preprocessing/identity-threshold change.

Confirmed code gaps: a missing worker response left busy latched indefinitely; visibilitychange closed the scanner instead of pausing/resuming; exhausted network retries stopped a healthy camera; no distinction between paused playback, failed track and hung worker. These are demonstrated failure modes, **not a diagnosis of the reported physical Android stoppage**, for which track/frame/worker telemetry was unavailable. Corrected current-worker/epoch guards, cancellable fresh-frame scheduling via requestVideoFrameCallback (currentTime fallback), conservative latency-aware liveness checks, bounded two-attempt component recovery with1/2-second backoff, track-event/listener cleanup, playback-only resume, background/BFCache resume and bitmap-transfer cleanup. No periodic restart/no-face restart. Canvas dimensions are reused; existing worker buffers/sessions, full-frame detection, circular gate and tensor disposal remain.

Unknown backend outcomes retain the original payload/key/context and pause identity processing while keeping the preview active. Retry reconciles the existing request, resumes fresh observations on a healthy camera, and cannot duplicate the domain record/event/deduction. Centralized actual-response-state messages distinguish ACTIVE, pending capture, duplicates, unknown, expired session and failures.503/429 retain uncertain outcomes, identical retry payloads and safe server/rate-limit feedback distinct from network loss. Duplicate rejection clears temporary progress, persists long enough to read, and closes/retries/new members clear stale messages. Normal modal contains no diagnostic panel, pilot technical notice, raw exception or competing error line. Authorized bounded health/quality/backend diagnostics are available outside the modal after closing; no raw frames/vectors/per-frame logs added.

Executed frozen-code regressions: all15 actual YuNet/SFace/WASM/PHP/MySQL/Angular/domain browser scenarios PASS in4.5min on final API/message code (public-photo simulated cameras).73 individually executed JavaScript cases PASS (5API,12camera,15core,26embedded,12health,3message);146backend/lifecycle/exclusion,34pilot,6authorization,10production-policy,3temporary-schema checks,15Python evaluation tests,18existing manual/domain scenarios and unlimited-browser contract PASS. Lost committed response keeps preview active and retries identical input; recovery preserves domain counts. Real-model accepted gated/unrestricted cosine1.0 and OpenCV/browser reference cosine0.9999513689 unchanged. Core/contract file hashes unchanged. Syntax/diff checks PASS. Source and human pilot fingerprints identical; only623ACTIVE, original references/two attendance-events/zero local deductions/pilot-ready/production-not-ready preserved. Ownership-checked disposable test databases removed. All8 served HTTPS JS/CSS assets match source; final module/worker cache revision stability-20261010-final and final-2 for API/messages/Angular entry imports, controller/CSS filemtime URLs preserved.

Initial verification failures: disk exhaustion interrupted an early concurrent run; an actual second browser context crashed under low disk; the final attendance-page test uses optional FACE_BROWSER_USE_SHM=1 on this host's available shared memory. Old UI selectors and an assertion firing on intermediate reconnect feedback were corrected without weakening acceptance. A reused already-enrolled disposable pilot required fresh fixtures. The final full15-scenario suite is clean. No production records/backups/biometric history were deleted for storage.

VERIFIED final frozen-source endurance: **1800030ms (30 actual minutes),117 completed observation batches,1618 detector attempts,224 no-face observations,801 held-track observations,zero errors**. Worker-stall,ended-track,background/foreground,three switches,three close/reopens,mute/unmute,unknown-network-outcome hold and playback-only recovery all passed. Pending-request hold kept actual preview frames advancing. Maximum observed worker/live stream/inference job was1; close left0 workers/tracks/jobs and no video callback. RendererRSS after warmup ranged291544–346536KiB (54992KiB/53.7MiB range,including shared pages); reported main-page JS heap stayed10MB (coarse browser metric,not total worker/model memory). Bounded recent512 detector timings:P50/P95307.7/321.4ms; recognizer91.5/117.4ms. Loaded AutomaticCapture classSHA6d90b0fda557a76441a9a918d5ae0ec78f7a21fef13acdc3515be6f2c4f24861 matches current frozen source. Static-server endurance performed **no backend/database writes**; real-backend persistence/retry/domain checks were verified separately by all15 browser scenarios. Earlier first-snapshot30-minute result is retained as historical evidence,not counted as current acceptance. Physical updated-code Android/iPhone endurance remains **NOT TESTED**; exact original phone failure cause remains **NOT ESTABLISHED**.

Short final simulated performance under concurrent test load: startup93.3ms; capture4597ms in automatic enrollment scenario; detectorP50/P95333.7/375.7ms, recognizer99.7/130.6ms; reported JS heap10MB, rendererRSS288772KiB including shared pages. Previous short circle run311.5/318.2ms,92.8/105.3ms and288044KiB; conditions differ, so no speedup or memory-improvement claim. Camera resolution preference640×480 and identity settings unchanged; no unmeasured phone frame-rate/resolution/ROI optimization or background-camera promise.

Changed source: pf/js/browser-face/{capture,embedded,api,messages,attendance,worker}.mjs; pf/js/controllers/batchAttendanceController.js (cache key only); pf/inc/browser-face-panel.php; pf/css/browser-face-embedded.css. Tests: tests/browser-face/{api.test.mjs,core.test.mjs,embedded.test.mjs,health.test.mjs,messages.test.mjs,a-pending.spec.cjs,attendance-page.spec.cjs,workflow.spec.cjs,z-continuous.spec.cjs,zz-stability.spec.cjs,endurance.cjs,prepare_public_fixture.py,README.md}. No unrelated model/backend/database change.

Usable now: ordinary manual attendance plus integrated isolated pilot capture/duplicate/continuous623 attendance, clearer truthful results and bounded recovery. Production remains BLOCKED by representative independent biometric validation and rollout approval; existing dark-condition rejection is not solved by stability changes. Exact Android step: hard-refresh attendance.php?face_pilot=1, Testingbranch2/batch33/current authorized session; Scan Arrivals, scan623, leave/return repeatedly for30minutes, background/restore once, close/reopen. Expect attendance marked/already marked and a responsive preview; use Retry only if requested. After closing, explicitly expand Practice camera diagnostics if a failure occurred. Repeat on iPhone Safari when available. Do not re-enroll623 or treat624/625 labels as different people.

Evidence: /tmp/ccc-face-stability-{before,after,served-assets,regression-results}-20261010.json; final-api-browser/unit-details/backend/pilot/auth/policy/migration/manual/evaluation logs; final-api-browser/unit-details logs and final soak report /tmp/ccc-face-stability-soak-20261010.json. All owned test databases,temporary8138server and generated public videos were removed after completion; private captures,models,backups and scalar audit evidence preserved. Final disk free136MB (96GB volume,100% rounded use) remains a real storage risk requiring separate capacity management.

## Real-batch capture and shadow rollout — October 10 (executable delivery)

Continued on master using the existing camera/worker/YuNet/SFace/PHP/MySQL/domain implementation. Recovery source copies: `/tmp/ccc-face-rollout-baseline-20261010`. No schema, model, preprocessing, identity-threshold, original-reference or production-matching setting change. User clarified that **all users/branches with ordinary Attendance access must have capture and shadow scanning without an additional permission/configuration step**. That is now the default; existing tenant/session/branch/roster/consent checks remain authoritative. LIVE still needs validated matching plus exact server-owned tenant/branch/batch approval, never a client flag.

Actual latest pilot investigation (read-only): 624 request8fcb2cd1...14:57:34 compared its submitted capture against623, candidate_count1, cosine0.1221643; request983cb9c5...14:58:11 cosine0.3956496. Both below the unchanged pilot duplicate cutoff0.7467828 and PENDING/PILOT_NOT_APPROVED. They captured successfully; the one-person pilot policy prevented activation.625 request164397ad...14:57:09 scored0.8002339 and returnedREJECTED_DUPLICATE. Scanfb2faf7d...14:58:35 UNKNOWN/BELOW_CUTOFF; scan8ad44716...14:58:44 matched623, ALREADY_MARKED, session2/batch33/attendance2/zero deduction, observation scores0.7753395–0.8099127. Neither624nor625 ACTIVE. These member labels do not establish distinct real people; prior reversible exclusions/audits remain. No new source/pilot biometric or attendance record was written by this task.

Implemented product changes:

- Normal attendance.php exposes Enroll Face and Scan Arrivals when the installed browser pipeline is available, independently of old sidecar/Testing-only UI switches. All ordinary Attendance-authorized operators use their existing selected member/batch/session. Manual Present/Absent/Punch remains available. Existing consent notice and operator-confirmed member selection are reused; no extra role or approval dialog added. Optional existing Check face supplies trusted selected-member observations on later visits, not model-predicted labels.
- Enrollment persists encrypted PENDING captures against the actual eligible selected batch. Status: **Face captured. Attendance setup is pending.** ACTIVE alone reports successful enrollment. Pilot-specific unapproved captures explain that only Test One can scan. Backend error/status mapping is centralized and sanitized; refreshed success clears stale errors, and changed context discards stale status callbacks.
- Normal batches default SHADOW. The same integrated continuous camera sends genuine browser embeddings, compares the consenting eligible roster's compatible ACTIVE/PENDING references, and journals bounded candidate/rejection summaries. Without validated thresholds it ranks candidates but accepts **no identity**. Persistent notice says **Face checks only. Please mark attendance manually.** A completed check holds the tracked face until departure and never updates attendance rows as present. No AttendanceDomainService call, activation, membership use or attendance event occurs in SHADOW.
- Modes CAPTURE_ONLY/SHADOW/LIVE are derived server-side. Exact private rollout scopes bind database/branch/batch, approval, expiry and validated report hash. A changed mode invalidates old scanner writes; committed-request recovery remains idempotent. No LIVE scope or production report was installed. Later approved LIVE activation changes only that batch's eligible identities while retaining tenant-wide active/pending duplicate conflict checks, immutable originals and transactional retries. Existing CLI activation also requires exact approved batch scope.
- Latest ordered consent audit excludes withdrawn ACTIVE/PENDING references. Fresh explicit enrollment grants are audited; validation-only capture does not grant attendance permission. Regression found timestamp-only comparison misclassified withdrawal/fresh capture in one second; audit ordering fixes this without admitting later withdrawn identities. Older explicit pending consent without an audit row remains valid unless a withdrawal exists.
- Real-data audit found source616's preserved RECOVERED_ENROLLMENT_CAPTURE reference lacked a full-observation row and was unnecessarily excluded by the initial shadow join. A narrow server-audited recovery case now admits that compatible unexpired consented original aggregate for shadow ranking, preserving all ciphertext. Missing unaudited evidence and LABEL_CONFLICT records remain excluded. This does not invent full observations or independent validation.
- Shadow summaries contain no images/embeddings and are UNLABELLED_SHADOW, never ground truth. Maximum10,000 retained summaries/batch is tested. A MySQL JSON-number/string-binding comparison bug discovered by the capacity test was fixed with explicit integer extraction. Private existing admin CLI exports recent summaries (0600) and supports explicitly authorized redaction after30days while preserving request recovery/attendance history. No production purge performed.

VERIFIED WITH AUTOMATED TESTS: full **17 real YuNet/SFace/WASM/PHP/MySQL/Angular/domain browser scenarios passed in4.8minutes**, using public-photo simulated cameras. Normal attendance.php capture/PENDING/manual-controls/shadow/no-write flow passed. Two final real-model shadow/normal-page scenarios reran after the recovered-reference change and passed in34.1seconds. **76 individually executed JavaScript cases** and all6 JS test files pass.146 existing backend/lifecycle/exclusion assertions plus **40 new rollout assertions** pass (174 combined lifecycle+rollout runner includes134 shared baseline checks).34pilot,6authorization,10production-policy,3temporary-schema,15Python evaluation tests,18existing manual/domain scenarios and unlimited-browser contract pass. Required manual suites were rerun with localhost socket; an initial default-TCP run skipped them and is not counted as evidence. Same-second withdrawal/regrant, latest withdrawal, immutable originals, scoped activation, shadow with validated policy still no writes, guessed clientLIVE, mode rollback, consent/branch/roster rejection, exclusions, private export/redaction and10,000-summary capacity are covered. Initial browser failures were stale fixture unavailable text, new button's decorative-icon selector, and the demonstrated consent-ordering bug; clean final run passes.

Real-model compatibility: accepted gated/unrestricted same-image cosine1.0; browser/OpenCV cosine0.9999513689. Camera/inference processing unchanged; AutomaticCapture classSHA6d90b0fda557a76441a9a918d5ae0ec78f7a21fef13acdc3515be6f2c4f24861 still matches the previously verified1800030ms actual-elapsed simulated endurance. No new30-minute or physical-mobile endurance claim. Current simulated performance: startup92.3ms; detectionP50/P95311.6/355.2ms; recognition92.1/100.4ms; reported pageheap10MB, rendererRSS291988KiB including shared pages. Conditions differ from prior tests; no speedup/phone-performance claim.

VERIFIED READ-ONLY LIVE READINESS: existing model installed; real batch/eligible-roster queries work in branches2,4,5. Branch2/batch27 has5 eligible members and **5 compatible pending references**; no capture/recapture needed just to begin shadow checks. Branch4/batch2 has1eligible member/0refs and needs consented capture. Private serving PHP-FPM probe confirms defaultSHADOW for all three sampled branch scopes and production_matching_ready=false; probe removed, no public endpoint/restart. All8 served HTTPS camera/controller/CSS assets match current source; entry/messages/module cache revisionrollout-20261010-final-2, capture/worker stability revision retained. Actual updated real-batch physical-camera flow remains **NOT TESTED** by the agent; prior operator-confirmed Android623 pilot attendance is preserved, not reclassified as a new test.

Source and human-pilot before/after fingerprints match structurally: source dbdff729bd1f5d64b14b6b7bbeae178fa80880533cbe388f81d6f926d997a99d; human pilot29ec0e8316ef6dbd0188694848cde21dc410e0e49eb9cc1f01740dcac5e8ce7e. Only623ACTIVE; original pinned reference/report and2attendance/events remain; zero local pilot deduction; pilot_ready=true, production_ready=false. New user captures before this task are included in the fresh baseline. Production records/old biometric audit/migration history/backups remain. Ownership-checked browser fixtures are disposable; human pilot is a different protected database.

Storage: root96GB/88GBused/**8.6GBfree**,11%inode use (~89%free), versus prior136MB. No cleanup/deletion was performed by the agent. Allocated source table bytes282498048 (~269MiB), pilot5390336; existing full encrypted capture payload averages17689bytes. Capacity now allows collection and source backup headroom;92%volume use still merits monitoring. No per-frame logs or unnecessary saved images added.

Changed source: classes/services/{BrowserFacePolicy,BrowserFaceService}.php; controllers/browserFace.php; pf/attendance.php; pf/js/controllers/batchAttendanceController.js; pf/js/browser-face/{attendance,embedded,messages}.mjs; pf/inc/browser-face-panel.php; scripts/browser_face_admin.php; services/face-inference/browser-face.env.example. Tests: tests/browser-face/{rollout.php,router.php,embedded.php,b-shadow.spec.cjs,c-batch-page.spec.cjs,a-pending.spec.cjs,embedded.test.mjs,messages.test.mjs}; docs/api/browser-face.md, tests/browser-face/README.md and this state file. Existing core/capture/worker/API/model/domain services retained.

Usable now / exact operator action: hard-refresh **normal attendance.php without face_pilot=1**, select any existing authorized branch/class/date/time. Use each consenting child's own row → Enroll Face → automatic capture → accurate pending result. Scan Arrivals now runs shadow checks with the selected eligible roster and remains open; it explicitly saves no attendance. Continue manual Present/Absent → Punch Attendance. Existing branch2/batch27 references can be checked immediately. Check face at later real visits collects separately selected labeled evidence; adjacent frames/ordinary manual Present/model predictions are not independent truth. Each distinct person must have the correct member label. The original isolated623 live pilot remains separately available.

BLOCKED productionLIVE: existing release requirements unchanged—representative consenting distinct/unknown people, child population, separate visit/device/lighting/full-roster decisions; preliminary20identity/100observation collection targets are not accuracy proof. No validated production thresholds/report or rollout approval exists. Next actions: collect through the already integrated member/Check face flows, use existing workflow evaluator for held-out duplicate/1:N/unknown/error/uncertainty results, approve the resulting matching configuration, authorize an initial exact LIVE batch, let guarded activation recheck valid pending references, physically verify saved attendance/events/deductions/rearming and roll back toSHADOW if needed. No new calibration application or production bypass required. Ordinary users retain only their existing Attendance permission.

Evidence: `/tmp/ccc-face-rollout-{inspect,rosters,gallery,pending-metadata,before,after,served-assets,regression-results}-20261010.json`; browser-verified/browser-shadow-final/unit-final/unit-details/backend/rollout/pilot/auth/policy/migration/domain/characterization/attendance/evaluation/FPM-readiness logs. Sensitive capture data stayed encrypted; no vectors/images in normal logs.

Final cleanup: both ownership-checked disposable browser databases/configuration files and task-generated public Y4M videos were removed after verification. Human-pilot/source final fingerprints still match the fresh task baseline. Source allocated bytes and12 encrypted source observation records remain unchanged. Final shadow query also verifies calibration/model/preprocessing/key compatibility for recovered aggregates; this does not reconstruct absent five-frame evidence. Syntax/diff checks remain clean. No production enrollment activation, attendance write, membership change, retention purge or database migration occurred.

## Operator review/confirmation and circular progress — October10 delivery

Implemented the user's revised attendance objective directly on master: **Scan Arrivals → collect suggestions → Finish Scanning → review/edit → Confirm Attendance → actual persisted row updates**. The prior automatic-write instruction is superseded by this explicit final confirmation requirement, including in the isolated pilot and future approved LIVE scopes. No schema, model, preprocessing, identity threshold, production release policy, original reference or AttendanceDomainService change. Recovery copies: `/tmp/ccc-face-review-baseline-20261010`.

Completed source changes:
- `classes/services/BrowserFaceService.php`: recognition now returns staged REVIEW_CANDIDATE or SHADOW_EVALUATED suggestions, never invokes AttendanceDomainService. Existing request journals bind each receipt to its operator/scan. The separate `confirm` command rechecks cookie-authorized branch/scan, scheduled batch/date/session, receipt/member associations and eligibility; only the verified selected list calls existing domain logic using **TEACHER_CONFIRMED**, not FACE_MATCH. No absent marking or activation occurs. Existing manual statuses remain intact; existing present records reconcile as already marked. Per-person deterministic domain keys plus reserved parent journal recover lost commits without repeated deductions. Partial responses expose actual per-member persisted/rejected outcomes. Cross-actor/context/changed-person/unknown receipts and unverified confirmation reject before writes. No images or vectors in drafts, journal responses or ordinary logs.
- Duplicate-name disclosure is backend-controlled: current authorized Attendance branch and visible active/unparked member/contact only. Cross-branch/inactive/parked matches still reject duplicates but disclose no name. Modal says “Possible duplicate: Face already registered to [name].” or “Face already registered. Contact an administrator.” Fresh submitted-capture comparison and immutable-reference fix remain.
- `controllers/browserFace.php`: confirmation resolves the server-owned batch scope from the authorized scan; client mode flags cannot change rollout authorization.
- `pf/js/browser-face/{embedded,attendance,messages}.mjs`: one deduplicated temporary list, no premature row update; normal recognition wording is added/suggested for review. Unvalidated consistent unique top ranks are explicitly UNVERIFIED_SUGGESTION and **unchecked**; the operator must independently identify the child before selecting it. Validated unknown/ambiguous/conflicting outcomes have no suggestion. Unvalidated ranking supplies no unknown-person acceptance guarantee or production approval. Finish stops camera/worker and invalidates callbacks; Back resumes the same scoped lease/list. Confirm alone updates persisted rows. Partial outcomes keep unsuccessful/unchecked members and accumulated saved results. Lost responses retain exactly the original reviewed list/key for Retry. Unsaved close/navigation/session changes warn; a cancelled change keeps the original context and confirmation refuses mismatched sessions.
- `pf/js/browser-face/capture.mjs`: only a guide-geometry callback added. `pf/inc/browser-face-panel.php` and `pf/css/browser-face-embedded.css`: thin SVG green progress ring uses that exact geometry and accepted observation progress (.2/.4/.6/.8/1), replaces horizontal progress, preserves blackout/transparent center, respects reduced motion. Existing YuNet face-size/landmark/alignment/blur/exposure checks supply Move closer/Move back slightly/Center face/Hold steady/Improve lighting; no monocular distance estimate/new threshold. Minimal readable review list, Remove, Back to Scanning, Confirm Attendance and finish control remain inside attendance.php. No technical overlays. `pf/js/controllers/batchAttendanceController.js`: module cache revision only, preserving mixed line endings. Final module revision review-20261010-final; core/worker contract unchanged.

VERIFIED WITH AUTOMATED TESTS: **19 real YuNet/SFace/WASM/PHP/MySQL/Angular/domain browser scenarios passed in5.0minutes**, with public-photo simulated cameras. This includes actual attendance.php pilot/normal branch shell, pending capture, authorized duplicate name, no recognition writes, two sequential identities in one continuous camera, editable review/unchecked shadow, explicit shadow manual save without calibration approval, final row updates, lost-confirmation response recovery, repeat protection, camera/worker/background recovery, circular geometry/progress and reduced motion. SVG accepted updates exactly .2/.4/.6/.8/1; outside black and center transparency unchanged. Full first17-scenario attempt passed16 but exposed a real Back to Scanning defect: old equal-observation suppression prevented a new review entry after confirmation. Clearing that transient state on resume fixes it; clean final19 pass. Temporary-test setup mistakes (JSON object-order comparison and missing manual command fields) were corrected without weakening assertions.

**83 individually executed JavaScript cases / all6 Node files PASS** (15core/5API/5messages/33embedded/12health/13camera). **204 combined backend/lifecycle/rollout/review assertions PASS** (180 reused amended assertions plus24 new review/disclosure checks, do not double-count included runners);35pilot,6auth,10production-policy,3temporary-migration,15Python evaluation,18existing manual/domain scenarios and1unlimited-browser contract PASS. Manual required suites used localhost socket. Source/human-pilot fingerprints match fresh task baseline; original623 remains the only active pilot identity, pilot-ready=true, production-ready=false, source enrollments/history/events/deductions unchanged. Tests modified only connection-local temporary or ownership-checked disposable fixtures, which were removed after completion. No production automatic attendance setting, biometric record or historical attendance was modified.

Compatibility/performance evidence: model sface-int8bq-fb143eea, preprocessing yunet640-similarity5-rgb255-v1 unchanged. Accepted guide/unrestricted cosine1.0; browser/OpenCV cosine0.9999513689. Final simulated automatic enrollment completed in4669ms (camera startup93ms); no hardware timing or speedup claim. Final short simulated test startup90.9ms, detectorP50/P95311.4/325.4ms, recognition93.1/113ms; reported pageheap10MB, shared rendererRSS316164KiB. Prior rollout311.6/355.2ms and92.1/100.4ms/RSS291988KiB used different conditions; no controlled improvement/regression claim. No new30-minute or physical-device endurance evidence. Current free disk~7.7GB while test videos present,7.8GB after removing only task-generated public Y4M files; no production data/backups/logs deleted. All8 served HTTPS JS/CSS hashes match source; private PHP8.3-FPM probe confirms the new confirmation method loaded and was removed afterward (no public endpoint/restart).

Changed tests: `tests/browser-face/{backend.php,lifecycle.php,pilot.php,review.php,camera.test.mjs,embedded.test.mjs,messages.test.mjs,create-integration-fixture.php,embedded.php,b-shadow.spec.cjs,c-batch-page.spec.cjs,attendance-page.spec.cjs,workflow.spec.cjs,z-continuous.spec.cjs,zz-stability.spec.cjs,progress.spec.cjs,zzz-shadow-confirmation.spec.cjs}`. Docs: api/browser-face.md, PRODUCT_GOAL.md, test README and this actual-evidence entry. No new testing application/database architecture.

Usable now: consented one-time capture with truthful PENDING/ACTIVE status, isolated pilot recognition, real-batch SHADOW review suggestions and existing operator-confirmed manual attendance through the same domain service. **Recognition itself cannot write attendance**. Calibrated LIVE matching/activation requirements remain BLOCKED by representative independent validation and rollout approval; manual review does not approve thresholds or activate pending templates. Updated Android/iPhone physical workflow is **NOT TESTED** by the agent; previous operator-confirmed Android623 success remains historical physical evidence, not a test of the new confirmation screen.

Exact Android/iPhone operator check: hard-refresh attendance.php; select authorized branch/batch/date/time. For enrollment use a consenting child's own row, watch accepted-observation ring and truthful pending/active or duplicate result. For scanning, collect faces sequentially; verify existing rows remain unchanged; Finish Scanning releases the camera, review names (unvalidated suggestions start unchecked), independently verify/select appropriate children, remove incorrect suggestions, Confirm Attendance. Only then should rows update and “Attendance confirmed” appear. Repeat the same person/session and verify already marked/no extra deduction; Back to Scanning, switch camera, close/reopen and background/restore once. Use Retry on interrupted confirmation to reconcile the original request. Human pilot623 remains separately isolated. Drafts are temporary browser-session state, warned/discarded on refresh/navigation; server scan lease remains two hours. Production automatic biometric accuracy is still unvalidated.

Evidence: `/tmp/ccc-face-review-{baseline-js,baseline-php,browser-first,browser-final,backend-final,pilot-final,js-final,embedded-final,auth,policy,migration,domain,characterization,attendance-regressions,unlimited,evaluation,cleanup}.txt`; `/tmp/ccc-face-review-{js-details,assets,fpm-readiness,before-20261010,after-20261010}.json`. Read-only fingerprints unchanged after cleanup. No biometric vectors/images exposed in ordinary logs.

## Production field release — October10, browser-face-field-20261010-1

Deployed and verified directly on master, base HEAD `2f64cb4ad02b3344a92a6f866992a33ddbfe78a4`, with the existing uncommitted implementation overlay preserved. Exact 98-file release manifest SHA256 `311e59b06b7b4390e4aae5de8146d8b510e0b51aa130d5f7b4dcf8ebb8d6c6c7`; private manifest/archive in `/var/backups/creativeculturalcentre/face-field-release/20261010/`. This is a content-identified release, not a new clean Git commit. User-owned changes and swap preserved. No schema/model/preprocessing/threshold change, production biometric activation, historical attendance edit or membership write by the agent.

Owner explicitly superseded the proposed Stage1 cohort: every user with existing Attendance access may use the module in their authorized branches. Owner confirmed consent is already obtained through invoice creation and rejected an extra consent gate. Final code uses the established invoice/program authorization and existing capture audit/withdrawal handling; no guardian registration or additional permission step was added. The briefly prepared guardian gate was removed before the final release candidate. Organizational invoice notice/consent adequacy is owner-confirmed, not independently legally audited.

Existing protected rollout file installed at `/var/lib/creativeculturalcentre/browser-face-field-rollout.json` (root:www-data,0640), selected through `.env`: version browser-face-rollout-v1, enabled=true, default_mode=SHADOW, scopes=[]. Tenant ccc_database's authorized Headquarter1/Testing2/Treon4/VanitaVishram5 branches and eligible existing batches use capture/PENDING + SHADOW + review/manual confirmation. No extra actor allowlist. Availability still follows existing valid sessions/memberships. Read-only actual checks: Testing2/batch27 has5eligible members/1scheduled session today; Treon4/batch2 has1/1; Vanita5/batch8 has0/0 today (business context, not a face authorization failure). Batch27 is Testing Skating, not a guessed real initial cohort. Other tenants were not migrated. Browser migrations01/02/03 and matching registry already present; no new migration executed.

Executable changes: BrowserFacePolicy supports controlled default modes, optional exact-scope operator_ids, global stop, permanent exact-cohort DISABLED and fail-closed configured missing/invalid/insecure files. Global LIVE default prohibited; existing exact report-bound LIVE gate preserved. BrowserFaceService blocks disabled operations/new confirmation writes while preserving committed request replay/recovery, exposes safe capture availability and confirmation scan context for bounded metrics. Controller uses authenticated actor in server rollout; safe config status reflects disabled capture. All review suggestions now start unchecked, including validated/pilot candidates. Existing selected verified confirmation remains the only domain attendance write, source TEACHER_CONFIRMED, biometric_acceptance=false. Messages/Angular bridge report rollback clearly; cache revision field-20261010-1. Existing admin CLI adds read-only field-status and authorized atomic global/batch disable-field. No new service, framework, database or camera page.

Source changed this release: `classes/services/{BrowserFacePolicy,BrowserFaceService}.php`, `controllers/browserFace.php`, `scripts/browser_face_admin.php`, `pf/js/browser-face/{attendance,embedded,messages}.mjs`, `pf/js/controllers/batchAttendanceController.js`; deployment `.env` and protected rollout JSON. Tests: new field-release.php and zzzz-field-release.spec.cjs; adapted embedded/messages unit and workflow/attendance-page/continuous browser checks for unchecked suggestions. Docs: api/browser-face.md, BROWSER_FACE_SETUP.md, new BROWSER_FACE_OPERATOR_GUIDE.md and this state. Capture/core/worker/CSS/panel/inference unchanged during this field release.

VERIFIED deployed readiness:11 public HTTPS JS/CSS/contract/model assets hash-identical to release source; module MIME application/javascript; WASM magic/application/wasm valid; anonymous direct enroll/attendance/confirm all401; tests PHP404 and .env403. Serving PHP8.3.7-FPM private probe: confirmation loaded, exact final service hash9c29c8431998e5b05ce9b7b9fcdeae6141a111a7ead5dd4ddc3bbf5478cc4e7e, field modeSHADOW, production matching ready=false, encryption round-trip and actual saved pending-template128dimension readable, pilot ready=true. Private probe removed. YuNet/SFace versions unchanged: sface-int8bq-fb143eea / yunet640-similarity5-rgb255-v1. Source registry and encryption key readable; no key rotation.

Actual tests: clean final **20 Playwright scenarios PASS in5.0min** with real YuNet/SFace/WASM/PHP/MySQL/Angular/AttendanceDomainService and simulated public-photo cameras. Includes actual attendance.php normal/pilot flows, all-unchecked reviews, no recognition writes, explicit manual confirmation/lost response/idempotency/partial recovery, duplicate feedback, model parity, continuous sequential arrivals, camera/worker/background recovery, circular progress and direct normal-controller field stop/config corrupt-file rejection. Prior full19 and additional direct1 also passed; do not double-count as extra acceptance scenarios. **84 individual JS cases/all6Node files PASS;232 combined backend/lifecycle/rollout/review/field assertions PASS**, including exact actor/tenant/branch/batch limits, broad authorized branch default, false-consent rejection, immutable pending reference, withdrawal, rollback/no writes, committed replay, final manual domain save.35pilot/6auth/10policy/3temporary-migration/15Python evaluation PASS. Existing18manual/domain scenarios (6domain+9characterization+3regressions) and unlimited-browser contract PASS. Syntax/diff checks PASS. Owned disposable fixtures removed; no public data imported into real identities.

Final simulated measurements: automatic5-observation enrollment4500.6ms, startup91.2ms; short5-batch performance startup92.9ms, detectorP50/P95308.4/322.1ms, SFace91.5/94.6ms, pageheap10MB, shared rendererRSS294236KiB. Guide/unrestricted cosine1.0 and OpenCV/browser0.9999513689. These are headless/public-photo tests, not physical device performance or a controlled speedup. No new30-minute soak/physical acceptance claimed.

Infrastructure VERIFIED: Nginx syntax and nginx/php8.3-fpm/mysql active; MySQL8.0.46; HTTPS and loading checks pass. Existing unrelated sdny hostname conflict warning retained without changing another site. Disk7.1GBfree initially;7.0GBfree after private backups and removal only of task-generated public Y4M files;93%used, inodes11%used. Available memory ~5GB during browser tests. Existing Nginx logs rotate daily/14compressed rotations; no per-frame server logs added. Fresh private26MB compressed transactional tenant backup/checksum; critical attendance,event,invoice,consent,template,enrollment/calibration/evidence/request tables restored into connection-local temporary tables in the existing test DB with matching dump-row counts. Verified durable local database/source/manifest copies in existing private backup directory and separate environment/key recovery file `/var/backups/ccc-face/browser-field-20261010.environment` (0600). Full-server/off-site recovery NOT TESTED; local restore rehearsal is not disaster recovery certification.

Production/human-pilot before/after fingerprints identical after cleanup: source dbdff729bd1f5d64b14b6b7bbeae178fa80880533cbe388f81d6f926d997a99d; pilot29ec0e8316ef6dbd0188694848cde21dc410e0e49eb9cc1f01740dcac5e8ce7e. Original623 remains sole ACTIVE pilot identity; historical two pilot attendance/events/reference and wrong-label exclusions unchanged. Source retains5PENDING references, zeroACTIVE browser enrollment. Runtime production acceptance=false; no report or provisional pilot thresholds installed. Recognition alone never marks attendance. Live duplicate identity decisions/ACTIVE production activation still require the existing validated configuration; PENDING storage is not identity approval.

Monitoring VERIFIED: existing bounded request journal, field-status seven-day branch-scoped aggregate capture/confirmation outcomes and pending counts; no vectors/images in new output. Atomic exact-batch/global rollback passes temporary-file backend and actual normal-controller HTTP tests; preserves ordinary manual attendance and committed history. Camera health remains bounded in-memory; removed suggestions and physical device errors are not centrally aggregated. No new analytics/per-frame logging. Operator instructions and incident/rollback commands are in BROWSER_FACE_OPERATOR_GUIDE.md and the latest BROWSER_FACE_SETUP.md section.

Usable now: authorized real-member automatic capture/PENDING, real-batch SHADOW suggestions, Finish Scanning, independently checked review, one operator-confirmed manual attendance save and unchanged manual fallback. Deployment is VERIFIED; updated physical operator acceptance is NOT TESTED, not replaced by simulated scenarios. Previous Android623 physical success remains historical evidence. Production automatic LIVE/ACTIVE activation BLOCKED by representative independent biometric validation and separate approved release, not a missing deployment flag.

Exact user acceptance: hard-refresh attendance.php; choose authorized branch/batch/date/session with eligible consenting members under the existing invoice authorization. Enroll each child from their own row; verify truthful PENDING and correct member association. Scan several children; rows must stay unchanged. Finish; independently verify/select names (all unchecked), remove wrong proposals; Confirm; only saved outcomes update rows. Repeat to verify no duplicate attendance/deduction; test Back, Switch, close/reopen, background/restore, interrupted confirmation Retry. Repeat Android Chrome/iPhone Safari/desktop where available; maintain camera for20–30min to validate physical endurance. Do not infer calibrated recognition accuracy from successful manual confirmation. Expansion scope is already owner-authorized across branches; operational acceptance/incident review remains necessary, and other-tenant migration or calibrated LIVE is a separate decision.

Evidence: `/tmp/ccc-field-release-20261010/` and protected durable release directory: release-manifest/source and candidate archives, database backup/checksum, restore-rehearsal.json, served-assets.json, fpm-readiness.json, data-before/after.json, roster-smoke.json, field/branch status, backend/pilot/auth/policy/migration/domain/manual/evaluation/unlimited/JS/browser test logs and fixture cleanup. No remaining implementation blocker for capture/SHADOW/operator-confirmed workflow. Remaining external validation: actual staff/physical-device field acceptance, independent biometric production evidence, organizational invoice/privacy/retention review and off-site recovery validation. No further permission question remains pending for field access.

Final legacy configuration audit: all branches1/2/4/5 have shadow_mode=true, raw_live_write_flag=true, effective_automatic_live=false and uncalibrated_test_mode=false. FaceAttendanceApiService::automaticAttendanceEnabled explicitly requires liveWrite AND NOT shadowMode. These existing flags were not changed; do not describe the raw flag as false. Browser release has readiness=false and SHADOW only. Evidence legacy-gates.json.

## Automatic LIVE product alignment — October10, automatic-20261010-1

IMPLEMENTED directly on master, base2f64cb4ad02b3344a92a6f866992a33ddbfe78a4; existing unrelated work preserved. Latest directive supersedes LIVE review: calibrated consistent unique eligible-roster matching now calls AttendanceDomainService directly, source FACE_MATCH, persists Present once and updates rows while scanning continues. SHADOW alone retains independently verified review/manual confirmation; it cannot write automatically. LIVE has no Finish Scanning/review/Confirm buttons, and normal Present/Absent controls are hidden in LIVE contexts without removing other manual workflows. Member rows show Not yet marked until an actual record exists. Backend ignores client mode flags, requires validated exact authorized scope, and binds LIVE leases to report/scope/threshold. Policy changes invalidate new decisions. Lost committed LIVE outcomes reconcile through deterministic domain attempt and reserved request journal, even after closing/rollback; no extra deductions. Existing compatible ACTIVE templates only; original references never adapt.

Existing activation reused/tested: encrypted PENDING capture activates later under approved LIVE after consent/model/preprocessing/tenant active/pending duplicate checks. Existing immutable aggregate/calibration/capture-session remains identical. Conflicts, withdrawn or expired evidence remain inactive; no blanket activation or new lifecycle. Admin activation now passes authenticated actor into existing optional scope restrictions. Face captured column uses backend state: Not enrolled / Pending / Enrolled / Duplicate–Needs attention. No per-row queries.

IMPLEMENTED scheduled finalization: existing owned scan plus separate exact LIVE scope finalization approval (`cutoff=SCHEDULED_SESSION_END`, reviewer/time); server resolves scheduled end Asia/Kolkata and existing attendance_settings.deduct_session_absentee. Before cutoff, SHADOW, pilot, missing authorization/settings and cancelled batch sessions reject. Final roster eligibility rechecked; existing Present/Absent/exempt preserved, unmatched eligible submitted to existing domain with ATTENDANCE_FINALIZED event/MANUAL policy source and cutoff/scope metadata. Domain lock now serializes all event types for one member/session, preventing Present/cutoff race. Partial outcomes/restarts reconcile committed members and safely retry uncommitted ones. Closing never triggers absence; finalized status survives close. Existing admin finalize-due command processes up to100 oldest unfinalized owned LIVE leases from today/yesterday with current operator/report/scope checks, including closed cameras. Open visible LIVE scanner also checks cutoff every30sec. Scheduler prepared, NOT installed or executed on production; requires approved LIVE/cutoff rollout. One attempted production command validation was rejected by automatic approval review as write-capable; it was not executed. Its unvalidated gate was safely tested through the temporary fixture instead.

Preview BLOCKED by image-purpose authorization: browser records store embeddings only. Read-only audit found one ACTIVE historical encrypted legacy portrait and seven cleared records, but no recorded authorization establishing browser reference-image retention/preview; general invoice consent is not assumed to cover this added purpose. No image reconstructed, retained, exposed or preview endpoint enabled. Status/activation work without preview. Supplemental references explicitly disabled in safe config; optional future design reuses quarantined independent identity evidence, immutable originals/versioned references, at most2 approved supplements, consent/retention, conflict/evaluation and rollback. No attendance-driven template adaptation or new model/schema/storage/framework.

Source changed: classes/services/{BrowserFaceService,BrowserFacePolicy,AttendanceDomainService}.php; controllers/browserFace.php; scripts/browser_face_admin.php; pf/attendance.php; pf/inc/browser-face-panel.php; pf/js/browser-face/{embedded,attendance,messages}.mjs; pf/js/controllers/batchAttendanceController.js. Tests changed: new automatic.php; backend.php,lifecycle.php,rollout.php,pilot.php,embedded.test.mjs; workflow.spec.cjs,attendance-page.spec.cjs,z-continuous.spec.cjs,zz-stability.spec.cjs. Docs PRODUCT_GOAL.md, api/browser-face.md, BROWSER_FACE_SETUP.md, BROWSER_FACE_OPERATOR_GUIDE.md, tests/browser-face/README.md and this state. Cache revision automatic-20261010-1; camera/worker/inference contract/thresholds untouched. No database migration, production flag/biometric activation, production attendance or membership mutation. Source-before archive/private manifest retained in /tmp/ccc-automatic-live-20261010.

VERIFIED WITH AUTOMATED TESTS: clean complete20 Playwright scenarios PASS in5.0min using actual YuNet/SFace/WASM, PHP/MySQL/Angular and domain service with simulated public-photo cameras. Includes actual attendance.php isolated pilot, direct automatic writes/row update, sequential distinct public identities in one camera, unknowns, explicit SHADOW review/no automatic writes, lost committed LIVE response/repeat deductions, camera/worker/track/background recovery, authorized duplicate feedback, blackout/progress/alignment/model parity. First run18/20 and second19/20 exposed stale test expectations (REVIEW_CANDIDATE/noattendanceID) and removed test variable, corrected without lowering thresholds; final20 PASS. Combined269 backend/lifecycle/rollout/review/automatic assertions PASS (includes prior suites, do not double-count),34 isolated pilot assertions PASS,86 individual JavaScript cases/all6Node files PASS,6authorization/10policy/3temporary-migration/15evaluator PASS, existing18manual/domain scenarios and1unlimited-browser contract PASS. Syntax/diff checks pass. Partial cutoff recovery, cancellation, no close-trigger, both absence deduction policies and cross-connection shared lock specifically tested.

Model/preprocessing unchanged sface-int8bq-fb143eea / yunet640-similarity5-rgb255-v1; existing regression browser/OpenCV cosine0.9999513689, accepted guide embedding cosine~1.0. Final simulated enrollment4534.1ms/startup95.7ms; short5-batch detectorP50/P95309.9/325ms, SFace92/99.4ms, heap10MB. Earlier field308.4/322.1 and91.5/94.6ms were separate headless runs; no controlled speedup or physical performance claim. No new30-minute endurance/physical-device acceptance performed.

VERIFIED serving deployment: private PHP8.3.7-FPM probe loaded finalization method/current service SHA2042007d7b804c6a156488b21e2ac488346ab22a0625f6d79c19eb816209c53e, matching_ready=false/field_modeSHADOW. Probe removed. Seven served HTTPS module/controller/contract/CSS assets match source with correct MIME/cache revision. Protected real tenant/human-pilot hashes for attendance/events/invoice items/templates/enrollments/calibration/evidence identical before/after and after ownership-checked disposable fixture cleanup. Original623 remains preserved. Source5PENDING; current calibration has30aggregate records across6member labels/3visits and zero validation records; labels are not proof of six independent people. No approved report/production thresholds. Human pilot remains one genuine identity with prior operator-confirmed Android evidence, never production accuracy. Disk6.9GBfree/inodes11%used; no logs/biometrics/backups/production history deleted; only this task's generated public Y4M videos and owned fixture DBs removed.

Usable now: existing field capture/PENDING + SHADOW/manual fallback; approved isolated pilot automatic scanner without review. Approved production LIVE path and scheduled finalization implemented/automatically verified, activation verified, production enablement BLOCKED by representative independent consented decision validation and exact-batch rollout/cutoff approval. Updated physical Android/iOS acceptance NOT TESTED. Immediate isolated check: hard-refresh attendance.php?face_pilot=1, Testingbranch2/batch33, select scheduled session, Scan Arrivals, present623 in circle; expect Attendance marked/Already marked and immediate row update with no review button; leave/return, switch, close/reopen/refresh/background and confirm no duplicate deductions. Real batches remain SHADOW. First production LIVE: existing independently labeled capture/Check face evidence→existing evaluator/error-budget and held-out approval→protected report-bound settings→one exact tenant/branch/batch LIVE plus cutoff approval→activate existing valid pending→schedule existing finalize-due command→physical class acceptance→progressive expansion. No recapture of still-valid pending references solely because approval came later.

Evidence: /tmp/ccc-automatic-live-20261010/{source-before.tar.gz,AttendanceDomainService.before.php,source-manifest.json,audit-before.json,audit-after.json,portrait-authorization.json,field-status.json,fpm-readiness.json,served-assets.json,automatic-tests.txt,pilot-final.txt,js-clean.txt,browser-clean.txt,authorization.txt,domain.txt,manual.txt,regressions.txt,unlimited.txt,evaluation-tests.txt}. Physical acceptance, image-purpose authorization and production biometric evidence/rollout are remaining human-required steps; no claim of validated child-recognition accuracy or production LIVE enablement.

Final status-column markup explicitly requires canonical ACTIVE state (never merely an active-template count). Both actual attendance.php pilot LIVE and real-branch SHADOW scenarios rerun PASS after that final display adjustment:2focused real-model scenarios/42.0seconds. Owned fixtures removed again; protected source/human-pilot hashes remain identical. Final11-source-file manifest SHA2565c86913bb203a01ad667bf5d05d94eda658220fb68bcae95f40fa518320adb9f. Seven HTTPS asset hashes and FPM code/gates verified; no production matching flag enabled. No pending executable test or daemon remains.


### October 11 — Existing Attendance access is the face operator model

IMPLEMENTED: removed optional operator_ids rollout gate; historical fields ignored
for compatibility. Existing authenticated Attendance and tenant/branch/member/
eligible roster checks remain. Validated report-bound exact batch LIVE, ACTIVE
references, consent withdrawals, emergency stop and destructive legacy replacement
approval remain safety controls. No new role, PIN, login, member association,
operator approval UI, permission table, schema or rollout flag change. Approved
LIVE retains automatic domain writes with no daily approval/review.

Found/fixed existing historical portrait endpoint identity override: request actor,
client and branch could influence authorization. It now uses authenticated context,
existing Member/Attendance/admin access, rejects missing/outside member branches,
and returns no-store rather than reusable portrait caches. Browser preview remains
disabled; no portrait capture/retention was enabled. Existing portrait deletion and
biometric references were not modified.

Baseline: all6JS files,6request-auth and10policy cases passed. Combined baseline
failed at validation split: a synthetic earlier visit mixed PHP Asia/Kolkata with
MySQL CURRENT_DATE around midnight. Corrected fixture to use MySQL for both dates;
production split already used database dates and was not changed.

VERIFIED automated:277combined backend/lifecycle/rollout/review/automatic assertions,
including real read-only central Attendance allow/deny, authenticated-context
binding, cross-branch denial, ignored old operator lists in SHADOW/LIVE, approved
report hash requirements and rollback;5actual historical portrait controller cases
(same-member access, cross-branch denial, spoofed actor denial, client override
ignored, absent member denied);6request-auth/10policy;all6JS suite files;18existing
manual/domain/membership scenarios plus unlimited-browser contract;34isolated pilot
assertions including source fingerprints unchanged. Two focused real-model browser
scenarios pass in16.8seconds: actual attendance.php authorized capture/PENDING,
SHADOW/manual fallback and direct field controller scopes/rollback with empty old
operator list. Real YuNet/SFace/WASM/PHP/MySQL, simulated public-photo camera input;
no new physical-device validation or recognition-accuracy claim. Owned disposable
browser fixture removed after execution. Syntax and diff checks pass.

Privacy audit (read only):117invoice_terms rows,4distinct general recording/fees
clauses;all5invoice_settings rows point to public TickleRight general terms and
configured automatic terms-message dispatch tnc_accept=0. This is not evidence
that the owner has no separate paper/invoice agreement. The inspected text does
not explicitly cover face attendance, encrypted templates/testing retention or
portrait viewing. Exact purpose-specific notice belongs in existing parent
onboarding, subject to organizational approval, with no new operator friction.
Public terms/app privacy sections inspected via web search after direct fetch
timeouts. Optional portrait purpose authorization remains BLOCKED. No consent
records or public agreements were rewritten and no biometric safeguards bypassed.

Changed:classes/services/BrowserFacePolicy.php;controllers/faceEnrollment.php;
tests/browser-face/{field-release,lifecycle,portrait-access}.php;
tests/browser-face/zzzz-field-release.spec.cjs;docs/{PRODUCT_GOAL,
BROWSER_FACE_SETUP,IMPLEMENTATION_STATE}.md;docs/api/browser-face.md;
tests/browser-face/README.md. Recoverable targeted source archives and actual logs:
/tmp/ccc-attendance-access-20261011/. Master preserved; accumulated changes untouched.
Disk7.3GBfree/inodes11%used. No production permission/biometric/attendance/membership
write, migration, new photo retention or LIVE activation performed. Current policy
matching_ready=false; existing broad Attendance-authorized field mode SHADOW.

Usable now:existing authorized capture/PENDING and SHADOW/manual fallback; approved
isolated demo preserved. Production LIVE remains BLOCKED by independent real-world
matching approval and exact batch rollout, never by a new face-specific operator
permission. Once enabled, existing Attendance users need no extra daily action.
Next:organization reviews existing onboarding purpose coverage; complete existing
independently labelled matching validation/first-batch approval; physical acceptance
of approved LIVE. No further operator access engineering is required for this task.

## October 11 — immediate normal Testing enrollment/recognition and verified logs

Implemented and deployed `testing-20261011-1` on master, Git baseline
`2f64cb4ad02b3344a92a6f866992a33ddbfe78a4`; accumulated implementation remains uncommitted
because this workspace's .git is read-only. No model/preprocessing, production
threshold, schema, original-reference, permission or AttendanceDomainService change.
Owner explicitly confirms physical parental authorization for face enrollment and
attendance, administered through existing onboarding; no popup/role/login/review added.

Actual read-only source investigation: five encrypted original PENDING enrollments
(member616/ref1/calibration16;617/ref4/calibration17;618/ref5/calibration18;
619/ref6/calibration19;620/ref7/calibration20), allbranch2/batch27 where recorded,
current model `sface-int8bq-fb143eea` / `yunet640-similarity5-rgb255-v1`, unexpired. Latest
October11 member616 capture31 at21:14:20 IST and617 capture32 at21:14:53 each retained5
observations in encrypted evidence, while originals stayed intact. Enroll requests
`aeaa5761-787e-4541-9bc9-dd1637cee138` and `e4257a92-b21e-46ba-bacb-07e7860e0cfe`
committed PENDING. Recognition `79133f14-80eb-4389-967e-579aa7829a2f` at21:15:29
returnedSHADOW_EVALUATED/THRESHOLDS_NOT_VALIDATED, not an accepted identity. No source
ACTIVE browser templates, attendance records or events. PENDING was real persistence
plus a missing validated production policy, not failed save. Existing old source
captures did not execute a calibrated duplicate decision because matching was not ready.

Observed source reference overlap is0.8485171–0.9751095 across616–620. Latest31 ranked619
first0.832894 vsselected6160.811449;32 ranked6190.832031 vsselected6170.785828. These
measurements cannot establish different people. No label exclusions/deletions were
applied without the owner identifying exact incorrect labels. TEST will reject such
multiple-above-cutoff matches rather than guess. Reversible exclusion remains existing
CLI functionality. New genuinely different member captures are supported; failed or
duplicate captures cannot replace an existing test reference. Repeated capture of a
new selected member face can supply a latest TEST-only pending reference while original
ciphertext/calibration/capture-session stay byte-for-byte unchanged. Production
activation still uses the original protected lifecycle; this is not automatic adaptation.

Operational changes: server-owned `testing_evaluation` in the existing protected
rollout file enables non-writing TEST only for authenticated ccc_database/branch2 and
existing authorized eligible batches/sessions. Tested scope cannot leak to other
branches/tenants; global/batch disable wins and expiry/report change invalidates old
leases. Uses the existing hash-bound physical genuine-only provisional pilot report
SHAff981235539848599bc13917caa2a95585653ed34f66844b28ecb9fb9b1fae63. TEST attendance
cutoff0.6513243536866755 and duplicate0.746782791039185 are reused without fitting/lowering
and explicitly unvalidated for multiple-person/child accuracy. Production thresholds
remainnull, acceptance_readyfalse and exact LIVE scopes empty. ExpiryOctober16,2026
18:10:59 IST. No automatic extension or LIVE promotion.

Normal attendance.php now reports database-backed Saved for testing / Enrolled /
Duplicate or Needs attention / Not captured. Enroll success is emitted only after
commit, with real record IDs/observation count/duplicate-check execution status.
Scan Arrivals showsRecognized:name or unknown/ambiguity, continues with track departure,
and has no review/Finish/Confirm dependency in TEST. TEST cannot write attendance,
events/deductions or confirm via direct API. Existing SHADOW manual review and approved
LIVE automatic attendance remain unchanged. Existing separate623 Practice pilot is
verified ready and unchanged; actual attendance-writing tests remain there, not in
shared Testing operational tables. Portrait preview remainsdisabled; no new images.

Logging: BrowserFaceAudit emits allowlisted JSON identifiers/state/reason/counters,
duration and actual persistence/replay metadata; enrollment_saved followscommit,
and recognition logs explicitly distinguish attendance_savedfalse. Request ID correlates
capture/save/decision events; endpoint supplies opaque X-Face-Request-ID even for
invalid requests. File `/var/log/creativeculturalcentre/browser-face/events.jsonl` is
private, writable by existing PHP pool, outside webroot, capped2MiB plus2rotations.
No vectors/images/parent papers/auth tokens/raw payloads/SQL exceptions or per-frame
logs. Existing MySQL journal remains transactional evidence. Up to8 recovery notices
per opened TEST/pilot camera reuse existing authorized API/idempotency; no new schema.
Production PHP-FPM actually wrote release_smoke_check; actual unauthenticated HTTPS
config401 and malformedJSON400 wrote backend_error/REQUEST_REJECTED and INVALID_JSON.
Actual private destination was inspected. Successful save/duplicate/match/failure
correlation and rotation were independently inspected in real PHP/MySQL disposable
fixtures with actual model camera input; no new human production capture is claimed.

Tests executed:
- `php tests/browser-face/testing.php`:318 combined PASS assertions (includes277
  existing automatic/field/review/lifecycle tests; do not doublecount), actual MySQL
  temporary tables,3 deterministic test identities, immutable originals/latestTEST
  capture, pending duplicates/fresh-capture regression, unknown/conflict/ambiguity,
  no writes/events/deductions, safe retry and inspected sparse event logs/rotation. A real temporary-table CHECK failure
  after a domain commit producesUNKNOWN_RECONCILE_IDEMPOTENCY, then exact-key recovery
  returnsALREADY_MARKED with exactlyone event/deduction. Server errors never falsely
  declare an unreconciled commit failed.
- testing-policy.php21PASS (existing10 +11 exact private scope/report/expiry/rollback).
- npm test:face6suitefiles PASS, including new TEST no-row-write/no-review/start cases.
- Existing20 real YuNet/SFace/ORT/PHP/MySQL browser scenarios PASS in5.4minute run;
  the2new TEST scenarios thenPASS in1.4minutes after fixing disposable router nullable
  metadata. Initial runs exposed fixture404/corruptJSON and a new audit pre-check ahead
  of DISABLED gate; fixed and retested. Never claim initial failed runs passed.
  New tests verify real automatic5-observation save/refresh/duplicate/status/logs,
  two distinct public-camera inputs sequentially and unknown rejection, no review or
  attendance. These are simulated public-photo cameras, not physical-child accuracy.
- Existing pilot34PASS/source fingerprints unchanged, authorization6PASS,
  portrait5PASS, migrations3PASS, evaluator15PASS. Manual/domain6+9+3PASS and
  unlimited-browser contractPASS. PHP/JS syntax andgit diff--checkPASS.
- Real-model OpenCV parity0.99995137; guide-accepted embeddingscosine1.0, outside/
  competing/cropped faces yieldnoembedding. Observed headless detectorp50 307.4ms,
  p95 320.8ms, recognitionp50 91.8ms/p95104ms; automatic capture4546.2ms. No hardware
  latency/accuracy or performance improvement claimed. No new endurance run needed
  for unchanged frame scheduler; updated-code physical Android/iPhone NOT TESTED.

Deployed verification: PHP8.3-FPM servesreleaseversiontesting-20261011-1, branch2 TEST,
scanning_availabletrue,test_evaluation_readytrue,production acceptance_readyfalse.
Five source PENDING references aretest-ready on refreshed server options. Source
attendance/events remain0. Branches1/4/5 remainSHADOW. ActualHTTPS200 source SHA parity
for attendance/embedded/messages/worker/contract, correct JS/JSONMIME; malformed and
anonymous access fail safely with logging. Existing pilot verify reportsisolatedtrue,
matching_readytrue,production_matching_changedfalse. No service restart or migration
was required; FPM read the updated existing configuration. Disk6.6GiBfree (94%used),
inodes11%used; bounded logs maximum6MiB. No data/biometric/backups/audits erased.

Files changed this iteration:
classes/services/{BrowserFacePolicy.php,BrowserFaceService.php,BrowserFaceAudit.php};
controllers/browserFace.php;routes/browserFaceRoute.php;pf/attendance.php;
pf/inc/browser-face-panel.php;pf/js/controllers/batchAttendanceController.js;
pf/js/browser-face/{attendance.mjs,embedded.mjs,messages.mjs};
tests/browser-face/{testing.php,testing-policy.php,testing.spec.cjs,
testing-sequential.spec.cjs,router.php,embedded.php,embedded.test.mjs,messages.test.mjs,README.md};
services/face-inference/browser-face.env.example;docs/{PRODUCT_GOAL.md,BROWSER_FACE_SETUP.md,
api/browser-face.md,IMPLEMENTATION_STATE.md};.env(FACE_WEB_EVENT_LOG only).
Private deployment files: existingbrowser-face-field-rollout.json Testing-only entry,
newbrowser-face-testing-report-20261011.json copied protected existing report, private
rotatingevents.jsonl. Source/config/env recovery and source manifest retained privately
at `/tmp/ccc-testing-face-20261011/`. No new Git commit. Model assets unchanged.

Exact next operator actions: hardrefreshnormalattendance.php; selectTesting branch2,
the correctexisting batch/date/time; enroll the correctly selected consenting member;
waitFace saved.Ready for testing; refresh/checkSaved for testing; repeat with genuinely
different people; Scan Arrivals; checkRecognized:name, leavecircle/show nextperson;
verifyunknown/ambiguous face isunmarked and operational rowsunchanged; close/reopen,
switchcamera, background/restore. Reporttime/member/batch/visible message ifwrong; logs
trace the actual safe decision. Current overlapping oldlabels must be confirmed for
existing reversible correction, not auto-assumed different. For actual test Present/
Already marked useexisting isolatedPractice623; normalTesting recognition is non-writing.
Remaining LIVE blocker: representative independently labeled multi-person/unknown/
child/device/session decisions and approved report/exact rollout. Paper consent is
confirmed byowner; no new camera consentor operator approval workflow is required.

Final small feedback correction: opening a Testing scanner with no eligible saved
reference uses the central response map and explicitly asks the operator to enroll
a member; it no longer collapses that backend response into a generic failure.

## October 11 — identity consistency fix and owner-authorized clean Testing reset

Deployed `testing-20261011-2`; master baseline remains
`2f64cb4ad02b3344a92a6f866992a33ddbfe78a4` (.git read-only; no new commit).
Scope: normal attendance.php, ccc_database, Testing branch2/members616–620.
No model, preprocessing, identity threshold, schema, permission or attendance-domain
change. Protected Testing configuration/report/expiry and other branches are unchanged.

Actual read-only investigation found17 physical-owner-reported captures33–49 from
October11 23:16:55–23:24:29 IST, each5 observations, unique capture_session and distinct
aggregate. Recomputed aggregation cosine0.9999999999999997–1.0. One successful new
TEST reference (member618/capture35);16 rejected attempts retained their previous
references. Originals:616/enrollment1/capture16;617/4/17;618/5/18;619/6/19;620/7/20.
All remain current-model PENDING until the authorized reset below; no ACTIVE identities
were created. Physical identities behind these labels are UNKNOWN, not5 proven people.

Root causes VERIFIED: duplicate enrollment reported top-1 despite four plausible
cross-member matches; saved-reference status obscured the latest rejected attempt;
camera stopped after rejection while its black container/ring geometry stayed visible.
Every rejected capture compared the actual submitted aggregate, with member623 absent
from this shared gallery by deliberate pilot isolation. No skipped check, vector reuse,
wrong member association or preprocessing defect was established. Private temporal
comparison audit includes reference/capture IDs, top/second scores, score gaps, policy
and actual journal decisions. Originals pairwise0.8485171–0.9751095. Example captured
FaceTest02/capture34: member616/capture31 .87280195, second619/capture19 .81177731.
FaceTest05/capture47:619/capture19 .80794622, second616/capture16 .80111235. Both four
members above unchanged provisional duplicate cutoff .746782791039185; a named unique
duplicate was unsupported. All rejected attempts have multi-member conflicts.

Fixes: group duplicate candidates by member deterministically; multiple above-cutoff
identities return ambiguity without a guessed name/ID. Fresh submitted-capture checks
and immutable usable originals retained. TEST gallery conflicts produce Needs attention
and cannot yield a recognized identity, even if only one newer reference matches.
Conflicted originals remain in duplicate protection until explicitly excluded. Actual
persistent state and latest attempt/capture are separate, with one batched journal read.
Known enrollment outcomes stop workers/tracks, clear observations/guide/ring and display
a clean readable result with Retry/Close. Retry creates a new frozen capture_session;
late config/batch responses cannot contaminate another generation. Close refreshes
backend statuses in the same batch/session. Private bounded logs now identify actual
capture_session/capture ID, preserved reference, unique matched reference/member,
actual decision and capture_saved_as_reference; recognition identifies selected reference
and a gallery fingerprint, never vectors/scores/images/tokens. Authorization still
controls duplicate identity disclosure; ambiguous matches disclose no guessed member.

Owner then explicitly requested removal of old data to begin new enrollment. Applied
the existing reversible exclusion to exactly35 prior calibration/evidence samples for
616–620, after snapshot write/readback. Each old calibration is excluded, evidence is
LABEL_CONFLICT and original enrollment RECAPTURE_REQUIRED/LABEL_EXCLUDED. Ciphertext
and request/audit history preserved, not deleted. UI maps the explicit reset to
Not captured. Authorized fresh recapture clears the exclusion and becomes PENDING,
immediately TEST-ready when unique; production activation remains separate. Retained
623 is only the exclusion tool's protected member, not an asserted physical relabel.
Durable encrypted recovery snapshot/audit:
`/var/backups/ccc-face/testing-reset-20261011/exclusion-plan.json` (0600,0700 directory).
Existing CLI rollback-label-exclusion can restore only unchanged audited records;
it refuses to overwrite a subsequently saved face. Do not run rollback after new
captures without reviewing this guard. No attendance, events, membership, other
members or private Practice records changed. Post-reset actual FPM verifies zero
available prior samples, zero source attendance/events/ACTIVE browser enrollments,
original ciphertext fingerprints intact, and isolated ACTIVE Practice623 preserved.

VERIFIED executable checks:335 combined backend assertions (original277 plus58 TEST
and logging checks),6 JavaScript suite files,22 real YuNet/SFace/WASM/PHP/MySQL browser
scenarios PASS in6.5min using simulated camera input. Original progress test initially
expected a permanent completed ring; corrected to inspect real accepted-observation
progress while capturing and assert its disappearance at result, then full suite passed.
Additional checks:existing reversible exclusion12 plus lifecycle baseline;pilot34;
Testing/policy21;authorization6;portrait-access5;migration3;evaluation Python15;
manual domain6/characterization9/regressions3 plus unlimited-browser contract. MySQL
TCP-root prerequisites initially skipped manual characterization; required localhost
socket rerun passed, not counted as a successful skip. PHP/JS syntax and diff checks pass.

Measured simulated short run: startup97.9ms, detector p50/p95 307.7/325.8ms, SFace
91.9/93.5ms;5 batches/25 observations. No preprocessing or scheduling optimization;
no claimed physical-performance or endurance improvement. Existing model parity/guide
regressions passed as part of the suite. Fresh Android/iPhone multi-person accuracy,
post-reset enrollment/scanning and prolonged mobile endurance are NOT TESTED by agent.

Actual deployment: PHP8.3-FPM returns testing-20261011-2/TEST, matching_ready=false;
HTTPS200 assets match source SHA with correct JavaScript/JSON MIME. Other branches
remain SHADOW; no production LIVE flags/settings changed. Actual private log destination
inspected:69 events correlated to all17 owner requests; old events are release1 and
cannot retrospectively acquire new reference/capture-session fields. New successful,
duplicate, recognition, error and recovery fixture events inspected and correlated to
actual MySQL rows/requests. Logs remain bounded2MiB plus2 rotations. Disk6.5GBfree,
94%used/inodes11%used; no production logs/backups deleted.

Changed source:classes/services/{BrowserFaceService,BrowserFaceAudit}.php;
controllers/browserFace.php;pf/attendance.php;pf/inc/browser-face-panel.php;
pf/js/controllers/batchAttendanceController.js;
pf/js/browser-face/{attendance,embedded,messages}.mjs.
Tests:tests/browser-face/{testing.php,testing.spec.cjs,progress.spec.cjs,
embedded.test.mjs,messages.test.mjs}. Documentation:IMPLEMENTATION_STATE.md and
BROWSER_FACE_SETUP.md. Private source snapshots, exact audit/report and test evidence:
`/tmp/ccc-face-consistency-20261011/`. No new application/framework/database.

Usable now:hard-refresh normal attendance.php, Testing/correct batch/session; rows
616–620 Not captured; enroll one independently known consenting person against one
member; wait Face saved.Ready for testing; refresh; attempt that same person under a
second member to verify duplicate; enroll genuinely different people against their
correct members; Scan Arrivals shows Recognized:name/unknown/ambiguity continuously.
TEST never marks operational Present or deducts membership; Practice623 retains actual
isolated-write integration. Close/Retry after duplicate has a clean result and fresh
session. No extra operator roles, consent popup or routine review.

Remaining evidence:fresh physical post-reset distinct/unknown-person recognition,
authorized correct identity labels and independent representative release validation.
No old-label mapping is needed to start again because the owner authorized the exact
reset. Production LIVE remains BLOCKED by unchanged validated matching and explicit
rollout approval, not by a new permission requirement. Testing provisional policy
expires October16 18:10:59IST and must not be promoted automatically.

Final verification after reset-status/reference-log refinements:3 affected real-model
browser scenarios PASS in1.6min (progress/result cleanup, two-identity sequential TEST
plus unknown, actual save/reload/duplicate/Retry/logs). Final335 PHP assertions and6 JS
suite files pass. New reset-row regression verifies a fresh PENDING capture clears the
excluded flag; recognition logs identify the exact capture51 reference in the fixture.
Durable reset audit remains the only authorized shared-database biometric mutation;
all actual operator physical post-reset tests remain NOT TESTED.

## October 12 — Testing Update Face, temporary Present and isolated Punch

Release `testing-20261012-1`, master baseline
`2f64cb4ad02b3344a92a6f866992a33ddbfe78a4`; no new Git commit (.git read-only).
Implemented inside normal attendance.php, existing Attendance access only. YuNet,
SFace/model/preprocessing, thresholds, encrypted original references and production
activation policy unchanged. Only Testing branch2 TEST mode gets this workflow.

Database-derived `test_ready` shows Update Face; excluded/rejected/unusable references
show Enroll Face. Successful fresh authorized captures select one latest compatible
TEST reference; duplicate/failed captures cannot replace it, and original ciphertext
and capture history remain. Compatible ACTIVE originals also remain immutable when
an authorized TEST-only capture is selected. New capture-session/update events are
correlated without images/vectors. PENDING remains PENDING, not production ACTIVE.

Accepted TEST recognition updates the correct member row to PRESENT_PENDING_PUNCH,
without changing real attendance_id/negative/membership usage. Unknown, ambiguous,
conflicting and stale results do not stage. Candidates are unique, receipt-bound to
operator/branch/batch/date/session. Staged member toggles are unavailable in TEST;
other modes preserve existing manual controls. Punch is disabled with zero staged
members and also rejects empty direct API requests. Punch stops camera/worker,
invalidates callbacks and freezes receipts. Cancelled non-writing TEST recognition
cannot stage another child after punch. Unsaved selection/navigation warns; declined
session changes preserve the old row/context before the legacy roster reset. Reset
unsaved scan discards only drafts. Confirmed rows are not erased.

POST test_punch rechecks exact session, member eligibility, current compatible
reference and conflicts before writes. It uses AttendanceDomainService in the
existing verified isolated Practice database, with two additive test attendance/event
tables. Domain membership reads use connection-local snapshots with unlimited sandbox
usage; no real deductions or permanent copied memberships. Practice623 tables and
reference remain unchanged. The guarded optional domain table mapping is unavailable
on source tenant connections and leaves normal domain behavior unchanged. Partial
infrastructure failure keeps the source request reserved and safely reconciles domain
events on exact-key retry. Known partial responses retain only unsaved members.
GET test_attendance reads persisted TEST Present after refresh/new operator login.
No Absent records, operational attendance or production LIVE activation occur.

Actual deployment proof through PHP8.3-FPM used existing member616 observations by
**stored-capture replay**, not a fresh camera: batch27/date2026-10-11/session0,
contact774. No TEST write at recognition; Punch created test attendance1/event1;
repeated requests/new status connections retain exactly one result. Source attendance0,
events0 and total membership usage13519 unchanged. Original Practice attendance2/events2
and ACTIVE623 ciphertext fingerprint unchanged. This historical TEST proof remains
visible for October11; it is not physical attendance acceptance or real operational
Present. Initial FPM log inspection exposed root-run rotation creating an unwritable
root-owned file. Shared service-group0660 files/setgid private log directory fix this;
subsequent actual FPM replay/punch produced9 correlated events in the inspected private
`/var/log/creativeculturalcentre/browser-face/events.jsonl`. Logs remain bounded2MiB
plus2 rotations; no raw images, embeddings, keys or auth tokens. Fixture logs separately
verify enrollment_updated/update_failed, empty punch, failure and persistence events.

Checks:368 combined PHP assertions PASS;6 JavaScript suites PASS. Existing22 browser
scenarios:21 passed in6.5min; one lacked its required disposable rollout-file environment,
then passed with correct fixture configuration. New normal attendance.php real-model
scenario PASS (24.2s): save/Update Face/reload, original preservation, pending row,
declined session-change warning, lost Punch response/idempotent save, persisted TEST
refresh and no operational writes. Two affected real-model scenarios rerun PASS in1.4min
(normal page plus two-public-identity sequential TEST/unknown/punch/reload). In total23
unique browser scenarios passed across full/targeted runs. Simulated public-photo
camera input is not physical-device accuracy. Domain6/manual9/regressions3/unlimited
contract, pilot34, auth6, Testing policy21, portrait access5, schema3 and Python evaluator15
PASS. PHP/JS syntax/diff checks PASS. Numerical/model/guide regressions unchanged.

HTTPS200 JavaScript/worker/contract assets match deployed source hashes and correct
MIME; anonymous config401/malformed JSON400. PHP-FPM/Nginx/MySQL active. Branches1/4/5
remain SHADOW, branch2 TEST, production matching readinessfalse. Migration applied only
to verified existing pilot after private backup/readback; no shared schema change.
Recovery/evidence: `/tmp/ccc-face-punch-20261012/`, including source baseline,
`pilot-before.sql`, actual DB/log proof and test outputs. Rollback disables TEST through
existing rollout controls, preserves test/history, and restores scoped source baseline
if necessary; do not revert unrelated worktree changes or drop stored test results.

Operator:hard refresh normal attendance.php, Testing and intended batch/date/time;
enroll correctly labeled consenting people, verify Update Face after refresh; scan,
see Present—Pending Punch; Punch; verify Present(Test) after refresh. Recognition alone
saves no attendance. Test repeat/unknown/duplicate/update/camera close/reopen. Actual
October12 fresh Android/iPhone multi-person punch/accuracy/endurance NOT TESTED by
agent. Production LIVE remains BLOCKED by unchanged independent representative
validation and explicit rollout authorization. TEST policy expiry remains October16
18:10:59 IST. No additional operator role, consent popup or ordinary review added.

Changed files: classes/services/{AttendanceDomainService,BrowserFaceService,
BrowserFaceAudit,BrowserFaceTestAttendance}.php; controllers/browserFace.php;
routes/browserFaceRoute.php; database/migrations/20261012_01_browser_face_test_punch.sql;
pf/attendance.php; pf/inc/browser-face-panel.php;
pf/js/controllers/batchAttendanceController.js; pf/js/browser-face/{attendance,embedded}.mjs;
tests/browser-face/{test-punch.php,test-punch.spec.cjs,testing.php,testing-sequential.spec.cjs,
embedded.test.mjs,create-pilot-fixture.php,router.php,embedded.php};
docs/{IMPLEMENTATION_STATE,BROWSER_FACE_SETUP}.md and docs/api/browser-face.md.

## October 12 — Enrollment pause/resume with capture-local identity verification

Release `testing-20261012-2`. Existing PHP/MySQL/AngularJS attendance.php flow,
YuNet/SFace tensors/models/aggregation, duplicate/gallery thresholds, immutable
references, Testing staging/Punch and AttendanceDomainService unchanged.

Root cause verified in AutomaticCapture: no face, outside-guide frames, disjoint
boxes, >2-second sample gaps, layout changes, visibility/mute and component recovery
called reset(), deleting accepted samples. Enrollment now pauses and retains them.
Progress stays at the accepted count. Unsuitable/outside/competing frames cannot
advance it. Explicit Retry/start/camera switch/member change/Close discards partial
samples; only internal recovery preserves them and requires fresh verification.
No unfinished biometrics enter browser storage or the backend. Memory holds at most
five accepted vectors plus three temporary returning probes in the current session.

Capture states distinguish waiting/collecting/no-face/quality/return verification/
uncertain/different/complete/saving/failed/completed. After interruption, three
suitable SFace returns must agree with EVERY accepted sample, their normalized mean
anchor and each other before the latest return becomes the next accepted sample.
Rejected/uncertain probes never move that anchor or enter aggregation. Even an
uninterrupted overlapping box cannot override a conflicting embedding. Stale worker
replies from before mute/layout/background/recovery are discarded. The ring freezes,
not resets; different-face feedback leaves Retry/Close available. A paused verification
has no old capture deadline expiring behind it. Existing bounded worker/camera recovery
remains; no periodic restart, extra model session or new inference queue.

Continuity uses existing server-policy settings, not new acceptance constants:
TEST same_min0.746782791039185 and different_max0.6513243536866755, three observations.
These are the previously documented genuine-only provisional pilot points, not
production-calibrated same/different-person discrimination. All comparisons must
pass the stricter existing cutoff to resume; a coherent sequence entirely below
the lower point is refused as different, and the remaining cases stay uncertain.
Where matching settings are absent, interrupted capture stays retained and requires
Retry for a fresh attempt. Production settings/readiness are not installed/relaxed.
Physical two-person continuity reliability is NOT TESTED; no absolute biometric
identity guarantee follows from these automated functional checks.

VERIFIED:7 JavaScript suite files;368 combined PHP backend assertions;25 real-model
browser scenarios PASS in7.3min from clean ownership-checked disposable databases.
New tests exercise 3/5 retention through actual YuNet no-face/outside/dark frames,
a different public-photo input, original return, real worker recreation, backend
save and duplicate rejection. Unit tests add ambiguity, same-position identity
replacement, unavailable policy, bounded probes, cancellation and stale replies.
Full suite verifies normal attendance.php Update Face/Pending Punch/persistence,
multiple sequential known/unknown arrivals, retry/idempotency, original references,
manual controls, LIVE/SHADOW restrictions and worker/track/background recovery.
Initial reused-fixture run had3 failures from leftover samples/originals; all three
passed on the clean full rerun. Manual domain6/characterization9/regressions3 and
unlimited-browser contract PASS;authorization6/policy21/pilot34/portrait5/schema3/
Python evaluator15 PASS. Initial socket prerequisite failure/skips were rerun using
the required localhost settings; not counted as successful tests.

Measured simulated public-photo camera: normal five-observation completion4507ms;
interrupted/different-face/return scenario10602ms with progress20/40/60/80/100 and
no backwards resets; returning verification1806ms from first suitable returning
result to acceptance. Detector p50/p95309/350ms;SFace93/99ms;startup91ms (5batches/
25observations). Prior release detector/SFace p50310/93ms: no claimed speed gain.
Coarse Chromium JS heap reported10MB before/after; renderer RSS grew78260→318504KiB
with model initialization. These short observations do not prove long-term memory
stability/mobile endurance. Streams/workers/partial vectors cleared after completion
or cancellation; no physical performance/success-rate claim.

Actual HTTPS200 module/worker/contract bytes match committed source; correct MIME;
anonymous config401/malformed request400. Actual PHP8.3-FPM returns new release,
TEST,provisional continuity,production acceptance_ready=false. Branches1/4/5 SHADOW,
branch2 TEST unchanged. Before/after live snapshot identical: source attendance0,
events0,membership usage13519;original Practice attendance2/events2,TEST attendance1/
event1,all inspected encrypted references including ACTIVE623 unchanged. No actual
face/attendance writes or schema changes in this deployment; tests wrote only their
owned disposable fixtures, then removed them. Services active;disk5.0GB free/inodes
unchanged. Fresh Android/iPhone testing of the new interruption behavior NOT TESTED.

Git blocker identified as the sandbox's read-only mount of .git, not ownership.
No chown/chmod/remount/history rewrite. Self-contained writable development checkout:
`/tmp/ccc-face-resume-20261012/repo`, branch`fix/face-enrollment-resume`; Git connectivity
check passes. Real commits:2edd3f663 checkpoint of the previous existing release;
ea3dd665e pause/identity/recovery with unit regressions;aeeed9fb9 real-model interruption
regressions and release assets. Documentation/evidence milestone follows. Main shared
master/index remain untouched. Deployed source matches the committed development
release; preserve this checkout for continuation, not an uncommitted live-only patch.
Recovery/evidence:`/tmp/ccc-face-resume-20261012/` (private), including scoped source
backup, commit/hash manifest, clean suite outputs, HTTPS/FPM and live fingerprints.
Rollback restores only the scoped prior files/new import chain; preserve all saved
references/attendance and unrelated worktree changes. No rollback/matching promotion
performed.

Changed:controllers/browserFace.php;classes/services/BrowserFaceAudit.php;
pf/js/browser-face/{capture,continuity,embedded,attendance}.mjs;
pf/js/controllers/batchAttendanceController.js;
tests/browser-face/{camera,core,health,continuity}.test.mjs;router.php;
pause-resume.spec.cjs;docs/IMPLEMENTATION_STATE.md and BROWSER_FACE_SETUP.md.

Next human check:hard-refresh Testing attendance.php;use two genuinely different
consenting participants. At3/5, remove A,verify60% retained,show B and verify no
advance/save,return A and verify Checking face then80/100. Repeat with blur/lighting/
background/reopen and Update Face. Retry must clear to0 and create a fresh session.
Finish normal Scan Arrivals→Present Pending Punch→Punch→persisted Present(Test).
If continuity stays uncertain, Retry is the safe fresh-capture path. Report actual
member/session/time and messages, not vectors/images. Production LIVE remains BLOCKED
by representative independent matching validation and rollout authorization; unchanged
TEST configuration expires October16 18:10:59 IST.

## Release testing-20261012-3 — attendance page and camera UI polish (October 12)

UI only: no model, threshold, preprocessing, pause/resume, duplicate, staging,
Punch, AttendanceDomainService, permission or LIVE-gate change. Roster loading
distinguishes Loading / Still loading (6s) / empty / session or permission
error / failure with Retry (45s bound; a late answer still applies). Face status
reads run in parallel and rows show "Checking…" until backend status loads.
Table: Name, Attendance, Sessions, Face Status, Face Action; Testing utilities are
collapsed under Testing tools. Modals: "Enroll Face — Name" / "Scan Arrivals" with
Class · Time, one status line, sticky footer, focus trap and Escape; camera
geometry CSS unchanged. VERIFIED: JS 118/118; browser 32/32 (25 existing + 7 new
UI scenarios) on clean fixtures; PHP face suites and attendance domain 6,
characterization 9, regressions 3 pass; deployed bytes match commit f6a92fb5c;
FPM reports testing-20261012-3 TEST, acceptance_ready=false; live DB fingerprint
unchanged. Physical Android/iPhone checks of the new layout: NOT TESTED.
Evidence: /tmp/ccc-face-resume-20261012/ui-polish-20261012-3/.

## Release testing-20261012-4 — AngularJS boot safety, exclusive roster states, front-camera mirroring (October 12)

No change to YuNet, SFace, preprocessing, thresholds, templates, enrollment, pause/resume,
duplicate detection, staging, Punch, AttendanceDomainService, permissions or LIVE gates.

Raw-expression root cause (reproduced): the roster status panels were static HTML (`ng-if`
branches plus `{{ }}`) with no `ng-cloak`, and AngularJS compiles only at DOMContentLoaded,
after every synchronous footer script (510KB CKEditor, Chart.js, ...). While one was slow the
page was painted but uncompiled, so `{{rosterLoadError}}`, "No members found", "Saving
attendance…" and two warning boxes were all visible. AngularJS injects its own cloak CSS only
after angular.min.js runs, so a failed/blocked script left the header exposed too. Fix: the
cloak rule is in `<head>` (top.php) and ahead of the markup in attendance.php, `#list-content`
is cloaked, dynamic text uses `ng-bind`, and a plain-HTML boot placeholder + guard shows a
skeleton until the controller reports ready, or "Unable to start attendance." with a working
Reload Page when AngularJS/a required script is missing, a module fails (modulerr), the
controller throws, or 45s pass. A late ready() still wins.

Roster states are exclusive: INITIAL, LOADING, SLOW_LOADING (6s), LOADED, EMPTY (only after a
successful empty answer), ERROR ("Couldn't load attendance." + Retry; session expiry offers
Reload Page; permission is separate; 45s bound). Saving attendance has its own flag. One notice
at a time. Class/session switches ignore stale answers and avoid duplicate requests.

Mirroring: it only worked when `getSettings().facingMode==='user'`; laptop/desktop webcams and
many browsers omit it, so the preview was never flipped. `facing.mjs` resolves the side from
settings, a single advertised capability, the device label, the requested side on phones with
several cameras, else front. Only the displayed `<video>` is flipped (CSS scaleX(-1)); frames
reach the models through createImageBitmap(video), which ignores CSS, so inference input is
unchanged (verified by pixels and by identical templates for front/rear/unreported cameras).

VERIFIED: JS 132/132; browser 59 scenarios on clean fixtures (existing 25 + 34 new/updated UI,
boot, mirror and parity); PHP face suites and attendance domain regressions pass. Physical
Android/iPhone: NOT TESTED. Evidence: /tmp/ccc-face-resume-20261012/boot-camera-20261012-4/.

## Matching validation status — October 12 (operator-confirmed labels + executed evaluation)

Operator confirmed the real identities behind the October 12 production captures (branch 2):
members 616, 617, 619 are three genuinely different people; member 618 was an intentional
duplicate test of 616 and member 620 an intentional duplicate test of 619. Both duplicate
attempts were correctly REJECTED_DUPLICATE (conflict_member_id 616 / 619). The records already
distinguish the three valid references (616/617/619, PENDING) from the two intentional duplicate
tests (618/620); originals preserved, nothing relabeled. Duplicate detection is validated correct
(no false duplicates among the three distinct people).

The existing evaluation pipeline was executed on the reconciled, independently-labeled evidence
(`scripts/browser_face_admin.php export-calibration` → `evaluation/calibrate_thresholds.py`).
Only 20 observations across 4 identities (616, 617, 619, 623) match the current model/
preprocessing contract and are non-expired/non-conflict (616x4, 617x1, 619x1, 623x14); 617 and
619 have a single in-contract observation each. Verdict: **approved=False**. Blockers (verbatim
from the pipeline): starting coverage requires >=20 identities and >=100 observations; separate
same-person and different-person pairs in distinct calibration and validation sets; two
independent visits per identity in each of calibration and validation; validation observations
for every identity; operator-specified false-acceptance budgets for DUPLICATE_THRESHOLD and
ATTENDANCE_THRESHOLD; complete known and unknown/ineligible attendance batches with unenrolled
people tested against a nonempty eligible gallery (no unknown-rejection trials exist).

Consequence: no approved matching report generated; the server-side readiness gate correctly
keeps LIVE disabled. Production remains default SHADOW, branch 2 TEST, 0 ACTIVE enrollments,
acceptance_ready=false; nothing is LIVE. The three distinct people, cross-session/lighting
captures and correct duplicate detection are real progress, but the documented operational bar
(>=20 identities / >=100 observations / withheld unknown identities / FAR budgets) is not met.
Remaining to unblock LIVE: ~17 more distinct consenting people with repeat-visit observations
(current model/preprocessing contract), withheld unknown-person trials against a real eligible
gallery, and the two FAR budgets; then re-run the same pipeline and, only if approved, install
the report and enable one authorized batch.

## Release testing-20261013-1 — Face Attendance Settings page and branch-wide rollout (October 13)

Commits `30237c251` (branch-wide rollout scope + `BrowserFaceRolloutAdmin` writer) and `26179304f`
(`pf/face-attendance-settings.php`, attendance-page gating, tests, docs). Face feature availability
(enrollment, Update Face, duplicate detection, Scan Arrivals, SHADOW) is now controlled per
branch/batch from the page, separately from LIVE readiness, which is unchanged and still blocked.

Production changes made for this release (rollback records in
`/var/backups/creativeculturalcentre/face-settings/20261013/`):
- Live tree fast-forwarded from `7c2b177f6` to `26179304f`.
- `FACE_WEB_ROLLOUT_FILE` now points at `/var/lib/creativeculturalcentre/browser-face-rollout/rollout.json`
  (root:www-data 2770 directory with `history/`, file 0660) so the PHP user can write it. Content was
  byte-identical to the previous file at cutover (sha256 `8267fe90…7ec9`); the old file is kept as
  `browser-face-field-rollout.json.pre-settings-page`. Roll back by restoring the previous `.env` line.
- Menu row `ccc_central.element` id 149 "Face Attendance" (Settings, module 75, url
  `face-attendance-settings.php`). The page itself enforces administrator (position 1); other users get 403.
  Reverse with `delete from ccc_central.element where id=149`. Appears after the next login.

Verification: JS 132/132, all PHP suites pass (rollout-admin 60), attendance domain tests unchanged,
Playwright 72 passed (10 settings tests against a real server and the real face endpoints). Production FPM
probes: release testing-20261013-1; settings page 403 for a non-administrator and 200 for an
administrator; displayed counts match the database. Writer smoke-tested as www-data on the real
filesystem with a disposable directory and synthetic tenant: atomic write, 0660 www-data files,
history copies and the face_mode_changed / face_feature_enabled|disabled / face_rollout_rejected events
(disposable directory removed afterwards; production rollout file unchanged, same sha256).

Not tested: a page POST against the production rollout file; physical Android/iPhone use of the page.
LIVE remains blocked by the validation gap recorded in the previous section.

### Face Attendance Settings — production verification and follow-ups (October 13)

Follow-up commits after `eb32a8de2`: menu entry hidden from non-administrators (shared `pf/inc/config.php`
filter using the page's own position-1 rule; also removed from the session copy that `top.php` writes into
JavaScript), history retention (newest 200 change copies kept, baseline never pruned), exact
LIVE-unavailable reasons on the page (`BrowserFaceRolloutAdmin::matchingReasons`), "Reset selected branches
to default", batch-table layout (no clipping at 1366 px, scroll hint below 1100 px, wrapping buttons on phones).

Production verification ran the real page, controller, policy, database and rollout file under PHP-FPM
(www-data) with a synthetic administrator session (actor 999999; no login or real session store used) on the
Testing branch (Kurla, id 2) and its test batch 33 "Face Camera Test 2026-10-09":
- Page change batch 33 → Off: rollout file gained exactly one DISABLED scope, page reload showed "Off
  (own setting)", the real endpoint returned DISABLED, `open`/`enroll` were refused with
  FIELD_RELEASE_DISABLED, other Kurla batches stayed TEST; reset restored the file byte-for-byte apart from
  `last_change`.
- Branch stop (Kurla) and reset: every Kurla batch DISABLED while Treon (branch 4) stayed SHADOW; restored.
  Attendance, scan, enrollment and request-ledger counts were unchanged throughout.
- Forced LIVE refused (MATCHING_NOT_APPROVED), bad CSRF refused, non-administrator 403; all logged.
- Global "Stop all" was not pressed in production (it would interrupt classes at other branches); it is
  covered by the automated suite only.
- A verification-harness mistake (a reused administrator session file) let one "non-administrator" probe
  run as the administrator and stop batch 33 for about 8 seconds (12:55:31–12:55:39 UTC); it was reset
  immediately and the file verified restored. The page's own check was then re-tested with a fresh session
  and returned 403.

### Last-change display and LIVE activation path (October 13)

The settings page no longer labels the production verification session as an administrator. Account
#999999 has no user record, so "Last change" now reads "during production verification testing (account
#999999 is not a real user; …settings were restored)". Real administrators are shown by name. The
rollout file, its history copies and the audit log keep the original `admin:999999` / `actor_id 999999`
entries unchanged. No further production tests use synthetic administrator sessions.

`docs/BROWSER_FACE_LIVE_ACTIVATION.md` documents the existing SHADOW→LIVE gates, the step sequence, the
current (unnamed) approval authority, the acceptance criteria, and the difference between the hard-coded
20/100 coverage floors and measured accuracy. Finding: the first LIVE batch cannot be authorized from
the Settings page. The page requires an ACTIVE enrollment, and enrollments only activate in a LIVE
batch, so the first scope must be installed by the documented root process.

### Inquiry, Follow-up & Member Conversion Flow Verification & Rectification (October 15)

Audited, tested, and verified the complete inquiry-to-member pipeline from end to end:
- **Catastrophic Bug Resolved**: In `controllers/member.php` (`insert_member` and `insert_member_v2`),
  `UPDATE inquiry SET doc = (SELECT doj FROM member WHERE contact_id = '...')` lacked a `WHERE` clause,
  wiping out `doc` across the entire `inquiry` table upon any member creation. Added targeted `WHERE contact_id = '...'`,
  conversion status flag, conversion date fallback, and auto-closed open follow-ups. Restored 550 corrupted
  historic rows in `ccc_database` and 545 in `ccc_database_test`.
- **PHP 8.3 Compatibility**:
  - `controllers/inquiry.php`: Initialized `$response['data'] = []` in `select_inquiry` to prevent `TypeError` on null array_push.
  - `controllers/followup.php`: Strict boolean check on `array_search` in `select_followup_history` to prevent `array_splice(..., 0, 1)` recording erasure.
  - `controllers/attendance.php`: Guarded `DivisionByZeroError` in `total_login_time` when `$max_valid_time_hrs` is 0 (unconfigured workshifts), guarded `format_time` redeclaration, and guarded monthly salary wage calculation.
  - Route handlers (`inquiryRoute.php`, `followupRoute.php`, `memberRoute.php`, `invoiceRoute.php`, `attendanceRoute.php`):
    Added `?? $_POST` stream decoding fallback for CLI, test runner, and webhook environments.
  - Safe delete mapping: Route `case 'delete'` safely falls back to `park_*` when controller lacks dedicated delete method.
  - Member selection: `controllers/member.php:select_member` accepts optional `from` parameter to select from both raw `member` and `member_contact_view`.
- **End-to-End Suite**: Created `tests/test_inquiry_followup_member_flow.php` covering 41 assertions across
  duplicate checks, inquiry insert/select, followup select/update/history, member conversion, membership invoice
  generation, and safe cleanup. All 41 pass. Detailed guide in `docs/INQUIRY_FOLLOWUP_MEMBER_FLOW.md`.

### API Silent Failure Audit, Elimination & Route Hardening (October 15)

Systematically audited, stress-tested, and rectified all 86 API route endpoints and their controllers to eliminate silent failures, unhandled exceptions, and unparseable outputs:
- **Zero Method Mismatches**: Resolved 39 route-to-controller method mismatches (e.g., `delete` vs `park`, `selectSessionActivity`, `selectBatchWiseSummaryReport`, `exist_heardFrom`, `exist_interest`, `select_todo`, `updateProduct`). Mismatches dropped from 39 down to 0.
- **Dynamic QueryBuilder & MySQL 8.1+ Strict Mode Resilience**: Resolved fatal `mysqli_sql_exception: You have an error in your SQL syntax near 'FROM ...'` crashes occurring when API callers omitted `col` parameters in dynamic queries (`batch.php`, `report.php`). Defaulted missing `col` to `SELECT * ` and enclosed queries in structured `try-catch` blocks.
- **Eliminated Corrupt Response Streams**:
  - Fixed double JSON outputs (`echo json_encode($_GET); echo json_encode(...)`) in `batchRoute.php` and `employeeSalaryRoute.php`.
  - Converted top-level `return json_encode(...)` to `echo json_encode(...); break;` in `assignmentRoute.php` (prevented blank 0-byte HTTP 200 responses).
  - Added standard error envelopes to missing `default:` cases in `subscribeRoute.php` and `zoomRoute.php`.
  - Converted plain-text responses (e.g. `"logo not found"`) to JSON envelopes in `registrationRoute.php`.
- **Autoloader Integrity**: Repointed Google PSR-7 autoloader bridge (`inc/google/vendor/autoload.php`) and established PSR-4 loader for Facebook Ads SDK (`inc/fb/vendor/autoload.php`).
- **Comprehensive Verification**:
  - Smoke test across all 82 JSON API routes: 82/82 passed.
  - `tests/batch-attendance/run.sh`: Scenario `BATCH-11` (routed actions crash test) passed; full suite exited with 52 passed, 0 failed, 2 known, 0 fixed.
  - Detailed documentation: `docs/API_SILENT_FAILURE_AUDIT_AND_HARDENING.md` and `docs/DECISIONS.md` (ADR-021).


