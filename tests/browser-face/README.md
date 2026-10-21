# Browser face tests

Current October10 Android continuation:12 real-model/backend browser scenarios PASS (3.2min),30 JS cases,146 backend/lifecycle/exclusion assertions,34 pilot checks,6auth/10policy/3migration/15evaluation and18manual attendance scenarios plus unlimited-browser contract pass. The added duplicate regression preserves an old PENDING reference while rejecting the newly submitted same-person capture; full duplicate scope is independent of attendance allowlists. Eight actual recorded Android morning batches reject after the fix in temporary-table replay. Operator-confirmed physical Android attendance and close/reopen repeat are now verified; post-fix fresh physical duplicate and uninterrupted physical recovery still need confirmation. See the latest IMPLEMENTATION_STATE.md; historical NOT TESTED statements below describe earlier runs.

Run from the repository root. These are required executable checks; failures or missing prerequisites must not be reported as successful validation.

```sh
npm ci --no-audit --no-fund
/var/lib/creativeculturalcentre/face-inference/venv/bin/python scripts/prepare_browser_face.py
npm run test:face
php tests/browser-face/authorization.php
php tests/browser-face/portrait-access.php
php tests/browser-face/policy.php
php tests/browser-face/migration.php
php tests/browser-face/lifecycle.php
php tests/browser-face/pilot.php
python3 -m unittest discover -s evaluation -p 'test_*.py' -v
```

`backend.php` uses connection-scoped temporary tables in the existing `ccc_database_test` database and invokes the real enrollment service and AttendanceDomainService. It refuses arbitrary persistent tenant writes. `authorization.php` exercises cookie/HTTPS/CSRF/origin/header boundaries. Unit vectors are only for deterministic logic tests, never substituted for inference or saved into production.

Prepare publicly available **test-only** photos and camera files outside the repo (not live calibration evidence):

```sh
curl -fL https://raw.githubusercontent.com/opencv/opencv/master/samples/data/lena.jpg -o /tmp/ccc-public-lena.jpg
curl -fL https://raw.githubusercontent.com/ageitgey/face_recognition/master/examples/obama.jpg -o /tmp/ccc-public-obama.jpg
curl -fL https://raw.githubusercontent.com/ageitgey/face_recognition/master/examples/biden.jpg -o /tmp/ccc-public-biden.jpg
/var/lib/creativeculturalcentre/face-inference/venv/bin/python tests/browser-face/prepare_public_fixture.py /tmp/ccc-public-lena.jpg
PLAYWRIGHT_BROWSERS_PATH=/tmp/ccc-playwright npx playwright install chromium
```

Use the host's standard Chromium prerequisites. During this implementation, missing libraries were downloaded/extracted into `/tmp/ccc-browser-libs/root` rather than installed globally. If using that prepared environment, prefix browser commands with `LD_LIBRARY_PATH=/tmp/ccc-browser-libs/root/usr/lib/x86_64-linux-gnu`. A future machine may have these libraries already.

Integration requires MySQL fixture administration (local root socket by default; `FACE_TEST_ADMIN_USER`/`FACE_TEST_ADMIN_PASSWORD` may supply a dedicated test administrator). It creates a **new**, exactly guarded database and grants the existing connector access only to that new namespace. It fails rather than reuse an existing DB. Central user/permission tables are read only to locate an authorized operator for genuine session/permission checks. No real attendance or face data is copied.

```sh
export FACE_BROWSER_TEST_DB=ccc_database_test_browser_10092026
export FACE_BROWSER_TEST_KEY=public-fixture-test-key
php tests/browser-face/create-integration-fixture.php
php tests/browser-face/create-pilot-fixture.php
# Cache the exact existing page libraries for the offline full-page test.
curl -fL https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js -o /tmp/ccc-attendance-ui-jquery-3.2.1.js
curl -fL https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.13.0/moment.min.js -o /tmp/ccc-attendance-ui-moment-2.13.0.js
export FACE_EMBEDDING_KEY=public-fixture-encryption-only
export FACE_EMBEDDING_KEY_ID=fixture-only
export PLAYWRIGHT_BROWSERS_PATH=/tmp/ccc-playwright
export FACE_PUBLIC_FIXTURE=/tmp/ccc-public-lena.jpg
export FACE_REFERENCE_VECTOR=/tmp/ccc-public-reference.json
export FACE_BLANK_FIXTURE=/tmp/ccc-public-blank.jpg
export FACE_MULTIPLE_FIXTURE=/tmp/ccc-public-multiple.jpg
export FACE_PORTRAIT_FIXTURE=/tmp/ccc-public-portrait.jpg
npm run test:face:browser
php tests/browser-face/remove-pilot-fixture.php
php tests/browser-face/remove-integration-fixture.php
```

Choose a different eight-character lowercase hex suffix if that test name already exists. The removal script verifies the database name and ownership token before dropping **only** its newly created fixture and revoking the scoped grant. Never run test keys or constructor-injected fixture thresholds in production. Deny `/tests/` at the production web server.

The dedicated localhost CLI test router injects a 0.99 **test-only** threshold through the same real service constructor used by the production controller. It does not install or impersonate live calibration evidence, and normal production acceptance remains blocked. Playwright exercises camera API/worker/WASM inference, enrollment/duplicate transactions, real unknown rejection, concurrent enrollment from separate authenticated sessions with multiple PHP workers, attendance uniqueness, response loss/retry, and browser cleanup. HTTP route interception changes only the destination to the isolated real PHP service; it does not mock successful identity responses.

Browser/OpenCV image resize paths differ. The real-model parity check independently runs Python's SFace alignment/encoder on the identical raster and five detector landmarks used by the browser. Public fixtures demonstrate reproducibility and spoofing limitations; they do not demonstrate live accuracy, mobile camera behavior, or liveness.

Embedded tests use the actual production Angular bridge (`attendance.mjs`), shared PHP panel, real AutomaticCapture/worker/ONNX models and real PHP/MySQL fixture. attendance-page.spec.cjs additionally loads the actual authenticated attendance.php shell and existing AngularJS controller on the separate ownership-checked disposable pilot. It tests enrollment/duplicate/scanning/row updates/refresh and rejects production write paths. Physical cameras and consented live calibration are not tested. The sequential test labels the known public input sequence and derives templates from actual YUV camera observations; no fabricated embeddings or relaxed backend threshold are used. Prepare all three downloaded public photos before running prepare_public_fixture.py to create `/tmp/ccc-public-mixed-camera.y4m`. Calibration samples generated by these tests are confined to the owned fixture and erased; they are never live evidence.

## Latest evidence and policy boundary

October 10 delivery, executed October 9 evening: all12 browser scenarios PASS (3.0min), including actual attendance.php with the protected `SINGLE_IDENTITY_LIVE_DEMO` policy and normal controller/service after a real-model fixture enrollment. No injected readiness or identity response is used in that portion. The disposable router's authorization report is explicitly `test_fixture_only` and never becomes consenting-person evidence. JavaScript29 registered cases/3files PASS; pilot isolation/domain34 PASS; backend/lifecycle/exclusion137 PASS; production policy10/auth6/migration3/evaluator15/manual attendance18 and unlimited browser PASS. Actual existing106 PHP numerical decision probes remain equal. The enabled consenting-person pilot has one pinned original623 reference; new identities cannot activate. Its genuine-only provisional settings are NOT validated production configuration. Fresh physical-camera attendance is NOT TESTED; operator steps are in the latest IMPLEMENTATION_STATE.md section. Existing live captures remain distinct from simulated public-photo tests.

Post-physical-capture continuation: 12 full browser scenarios passed again in3.0minutes;27 JavaScript cases/3files,137 backend assertions,15 evaluator tests and existing attendance regressions pass. New camera regression reproduces an old worker error stopping a restart or clearing a replacement capture's shared preview; epoch/active guard fixes it while current errors still release resources. Six new operator-confirmed physical captures were inspected separately. Controlled replay of recorded physical observations created only one test attendance/event in the existing consenting-person pilot; this is not a physical live scanner trial. Its genuine-only duplicate setting missed three newer templates and was never installed. Read the latest IMPLEMENTATION_STATE.md and appended physical section of RECOGNITION_FAILURE_INVESTIGATION.md before using earlier snapshots below.

Recognition-investigation continuation: full 12 browser scenarios passed in 3.1 minutes, including committed-response loss followed by camera closure, exact-key recovery and fresh scanner lease. JavaScript now has 26 cases/3 files; backend/lifecycle/exclusion 137 assertions, Python evaluator 15 tests. Existing manual/domain regressions remain passing. A further real-model inference compatibility scenario passed after extending reference_encoder.py. Physical camera tests remain blocked.

Development-only preprocessing diagnostics reuse the existing inference harness with `--development-diagnostics`; then run the existing virtual-environment Python reference_encoder.py with `--development-diagnostics INPUT OUTPUT`. Input/output and diagnostic images must be private, outside the web root. Official OpenCV alignment/features use the same prepared YuNet/SFace assets; no held-out images are inspected in this mode. See [recognition investigation](../../docs/RECOGNITION_FAILURE_INVESTIGATION.md) for commands, unchanged recognition results and actual numerical evidence. The original three-positional-argument reference command remains supported.

Final full Chromium run: 12 passed in 3.0 minutes. PHP lifecycle: 125 assertions; pilot: 15; authorization: 6; policy: 9; evaluator: 6; JavaScript: 3 files/23 cases. All disposable fixtures are ownership-checked before removal. Pilot tests verify source attendance/events/membership usage/template status fingerprints remain unchanged. Finite membership deduction is exercised only in a disposable pilot. The live consenting-person pilot is a different protected database, never removed by these fixture scripts.

Private real-person evaluation must use consented captures, complete batches, server-scoped eligible galleries, independent visits/validation and reviewed error budgets. The fixture tests' public images, synthetic vectors and strict constructor-injected policy are never calibration evidence or deployed acceptance configuration. See docs/BROWSER_FACE_FINAL_DELIVERY.md for live blockers.

The final additions verify single-face track discontinuity, unchanged-batch-only repeat suppression, original pending/active reference preservation, expiry feedback, later activation sweeps, fresh browser-session recognition and a real-model empty attendance camera waiting beyond its former enrollment deadline. Existing manual attendance characterization (9), regressions (3), domain suite (6) and unlimited-browser contract also passed. Physical person/device outcomes remain BLOCKED; do not rename virtual-camera tests as physical validation.


Public dataset and label-integrity verification (2026-10-09):
- `php tests/browser-face/exclusion.php`: existing125 backend/lifecycle assertions plus12 new guarded temporary-table exclusion checks.
- `python3 -m unittest discover -s evaluation -p 'test_*.py' -v`:14 tests, including exact PHP-style floating accumulation. Structural unit fixtures are not biometric calibration data.
- Actual dataset inference/evaluation commands and observed results: [PUBLIC_FACE_DATASET_EVALUATION.md](../../docs/PUBLIC_FACE_DATASET_EVALUATION.md). 144 real browser model runs /138 quality-approved embeddings /106 actual PHP identification and duplicate probes. Data protected outside web root; no database enrollment or policy installation.
- Four existing real-model inference/camera browser scenarios rerun and passed this task; simulated camera remains distinct from physical validation.

October10 circular-window regression: `guide.spec.cjs` runs actual YuNet/SFace on accepted, outside, cropped and competing public-face scenes and verifies accepted embeddings are unchanged. `core.test.mjs` checks shared cover/contain/mirror/orientation transforms and stale geometry rejection. The actual attendance.php test samples opaque-black/transparent-center mask pixels at mobile portrait/landscape and desktop sizes, verifies short feedback and no repeated requests while a marked track remains.

Run the existing `prepare_public_fixture.py` command before browser tests: it now frames public faces within the guide using the unchanged detector/alignment contract. Sequential video includes departure between arrivals; adjacent repeated frames and public/synthetic inputs remain functional fixtures, never independent biometric validation. Generated videos are disposable and removed after this run; downloaded public inputs, models, backups and scalar evidence remain.

October10 stability tests add actual worker/track recovery, background/resume and cancelled-video-callback protection. `zz-stability.spec.cjs` uses the real model, PHP and disposable MySQL/domain service and verifies unchanged attendance/events/deductions during recovery. Operator response-state mapping is tested separately; diagnostics are outside the camera modal.

Actual elapsed-time endurance (static localhost server, **no backend/database writes**):

```sh
# Reuse the existing public fixtures/model assets. Compact video stores fewer
# identical frames while preserving 13-second appearances/2-second departures.
FACE_FIXTURE_FPS=1 /var/lib/creativeculturalcentre/face-inference/venv/bin/python tests/browser-face/prepare_public_fixture.py /tmp/ccc-public-lena.jpg
php -S 127.0.0.1:8138 -t .
# In another terminal, with the same Chromium library/browser paths above:
node tests/browser-face/endurance.cjs
```

Default duration is 30 actual minutes. `FACE_SOAK_MS` can shorten local debugging runs, which must never be reported as 30-minute endurance. The bounded report is `/tmp/ccc-face-stability-soak-20261010.json`; it contains counts, resource measurements, lifecycle events and timing distributions, no images or embeddings. Worker stall, ended track, pause/resume, switching, reopen and mute/unmute are injected; they are simulated fault evidence, not physical mobile suspension. Video/model processing is real. Attendance persistence/idempotency and network-response loss are independently covered by the real-backend browser tests. Run browser suites serially on this nearly full server; never erase live records/backups to make tests fit.

Android physical endurance: hard-refresh the authorized Testing branch2/batch33 pilot, open Scan Arrivals and keep it open for 30 minutes. Use the retained623 face, leave the circle completely and return several times. Verify marked/already marked, responsive preview, continued scanning, one background/foreground recovery, then close/reopen. After closing, expand authorized Practice camera diagnostics outside the modal if a failure occurred; report health events/state/frame/detection/backend ages, never images/vectors. Repeat on iPhone Safari when available. Physical endurance is not established by these automated fixtures.

On this host, `FACE_BROWSER_USE_SHM=1` makes the attendance-page browser use the available shared-memory filesystem instead of Chromium's default temporary disk memory, avoiding crashes when opening a second context on a nearly full root disk. It is an optional test launch setting, not application camera behavior. `FACE_FIXTURE_STATIC_FRAMES=1` stores one identical static-photo frame at the same20fps playback rate for low-storage test runs. Final endurance records the loaded AutomaticCapture class SHA; compare it with the frozen source hash rather than counting an older snapshot as final-code acceptance.

Final stability verification (October10): all73 individually executed JavaScript cases,all15 real-model browser scenarios (4.5minutes),146 backend/lifecycle/exclusion assertions,34 pilot assertions and existing authorization/policy/migration/evaluation/manual attendance regressions pass. Final-source elapsed endurance passed1800030ms with117 batches/1618 detector attempts,zero failures,one worker/stream/job maximum andzero resources/callbacks afterclose. The final report records loaded-source SHA and matches the delivered AutomaticCapture class. Fault injections include unresolved network hold with fresh preview frames and playback-only recovery retaining the existing stream/worker. RendererRSS warmup range54992KiB includes shared pages; this proves a bounded observed30-minute test,not indefinite memory stability or physical-mobile accuracy. The original human-pilot/source fingerprints and production gate are unchanged. Physical updated-code Android/iPhone endurance remains NOT TESTED. All owned disposable schemas,generated public videos and temporary static server were removed after verification; regenerate public videos before rerunning.

Real-batch rollout verification (October10): `php tests/browser-face/rollout.php` reuses the existing temporary lifecycle/database fixtures. It covers automatic capture/PENDING, immutable original references, normal shadow roster matching, no attendance/deductions/activation (even with validated matching), consent withdrawal/regrant, exact server LIVE scopes and rollback, scoped activation, recovered original aggregates, exclusion integrity, private summary export/redaction, and the10,000-summary limit. It never operates on live-member tables. The full17 actual-model browser scenarios pass; b-shadow.spec.cjs uses the normal controller/policy and actual models/MySQL. c-batch-page.spec.cjs exercises normal attendance.php and its Angular controller/manual controls, using read fixtures for legacy lists and the actual face API/model/backend (no identity or write result mocks). Final two shadow scenarios rerun after the recovered-reference fix pass.76 individual JS cases pass. Public/simulated inputs are functional evidence only, not biometric production validation.

Normal attendance operators need no additional face permission or shadow allowlist. Use attendance.php without face_pilot=1 for real batches: consented member capture remainsPENDING until approved LIVE activation; Scan Arrivals performs shadow checks only and manual attendance stays available. Existing Check face on separate visits supplies trusted labels; predictions never supply labels. Do not install test constructor thresholds or synthetic/public operating points into production. LIVE needs the existing approved validated report plus an exact private server rollout scope; see docs/api/browser-face.md. No production LIVE configuration was installed.

October10 review-before-confirm regression: `php tests/browser-face/review.php` uses the existing connection-local temporary schema. Camera recognition must return only a staged review suggestion in LIVE/pilot; SHADOW unvalidated suggestions are unchecked and require independent operator verification. Only `confirm` may call AttendanceDomainService, using TEACHER_CONFIRMED manual source. Browser tests now exercise finish/back, review editing, lost confirmation response recovery, per-session receipts, circular accepted-observation progress and real-model shadow/manual confirmation. These use simulated public-photo cameras, not physical-device/biometric release evidence.

Field-release regression: `php tests/browser-face/field-release.php` includes the existing review/lifecycle/rollout runner (232 combined assertions in the October10 execution, avoid double-counting). `zzzz-field-release.spec.cjs` requires `FACE_WEB_ROLLOUT_FILE=/tmp/ccc-browser-field-fixture.json` containing the protected test-only legacy-compatible rollout configuration; it modifies/restores only that exact disposable file to exercise the actual controller's global stop, corrupt configuration and authorized SHADOW default. Production uses its separate private rollout file. Full current suite:20PASS/5.0min; physical devices remain unverified. Existing invoice consent/Attendance permission are reused; all suggestions require explicit selection. See the field release entry in IMPLEMENTATION_STATE.md.

Latest automatic directive: approved LIVE now saves accepted arrivals directly;
SHADOW alone retains review/manual confirmation. `php tests/browser-face/automatic.php`
reuses the existing connection-local temporary fixtures and tests LIVE/idempotency,
stored pending activation, report-bound lease changes, unknown/conflicting rejection,
cutoff authorization and absence deductions, cancelled sessions, partial recovery and
cross-connection domain-lock contention. It includes field-release.php tests; do not
add both totals. Model/browser scenarios now assert no LIVE review buttons, genuine
real-model/backend automatic row updates and no repeated deductions. Their cameras
remain simulated public-photo fixtures, not production biometric validation.

October11 Testing-branch regression:
- `php tests/browser-face/testing.php`: actual temporary-table MySQL persistence,
  three deterministic identities, pending duplicate protection, original-reference
  preservation with fresh TEST-only references, unknown/conflicting rejection,
  no attendance/events/deductions, inspected event-log correlation and rotation.
  Includes `automatic.php`; do not add both totals.
- `php tests/browser-face/testing-policy.php`: synthetic protected-file metadata
  tests exact tenant/Testing branch/report/expiry/rollback scope; never deployed.
- `testing.spec.cjs` and `testing-sequential.spec.cjs`: actual automatic capture,
  YuNet/SFace/ORT, PHP/MySQL, refresh, duplicate feedback, sequential TEST recognition
  and unknown rejection without a review queue or attendance writes. Set
  `FACE_WEB_EVENT_LOG` to a private writable temporary directory/file for these tests.
  Public simulated camera inputs are functional evidence, not biometric validation.

## Attendance boot, loading-state and camera-mirroring specs (testing-20261012-4)

`zzzzz-attendance-boot.spec.cjs` loads the real attendance.php and injects boot problems by
delaying or removing real script requests (slow footer script, missing AngularJS, missing
ngSanitize/Chart glue, 404 controller, throwing controller) and roster problems (slow, failed,
empty, expired session, refresh/switch while loading, saving). A trace recorded from the first
parsed byte asserts no raw `{{ }}`, at most one status phrase and one roster panel at any time.
`zzzzz-camera-mirror.spec.cjs` needs `python3 tests/browser-face/prepare_asymmetric_fixture.py`
(left half white, right half black) and measures rendered pixels for front, rear, unreported
facing, manual switching and phone/iPhone-style labels, plus the unflipped model frame and the
circular guide. `zzzzz-camera-parity.spec.cjs` uses the Lena fixture and real models to show the
same enrollment template for front, rear and unreported cameras. `asset-versions.test.mjs`
checks that every face module import, the page's modulepreload list and the release label agree.
The fixture now has a fourth session (10, 16:00) used only by these specs.

## Face Attendance Settings (testing-20261013-1)

`php tests/browser-face/rollout-admin.php` covers branch-wide policy scopes and the rollout writer with temporary files only. `zzzzz-face-settings.spec.cjs` drives the real `pf/face-attendance-settings.php` as an administrator and as an ordinary operator against the real rollout file (the disposable field fixture, restored afterwards), the real `routes/browserFaceRoute.php` policy and the private audit log; it needs `FACE_WEB_ROLLOUT_FILE=/tmp/ccc-browser-field-fixture.json` and `FACE_WEB_EVENT_LOG` in the environment. The fixture router accepts `/fixture-login?shell=1&position=1` to sign in as an administrator.
